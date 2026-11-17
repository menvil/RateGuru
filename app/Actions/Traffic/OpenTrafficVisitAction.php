<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficChannel;
use App\Enums\TrafficKnownReason;
use App\Enums\TrafficSpendBasis;
use App\Models\Game;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Capture\VisitorSignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The page ran in a real browser: the first call its script makes hands back
 * the arrival token, and the visit it names is marked opened — or, for an
 * unpaid arrival, the visit is recorded now, never on the request for the
 * page, so a crawler that runs no script leaves nothing behind. While a paid
 * click still holds the browser, an unpaid return is that click's: no new
 * visit, and the game's steps go to the click. An unpaid visit to the same
 * game within 30 minutes is the same visit. Returns the visit the page's
 * steps are credited to, if any.
 */
final class OpenTrafficVisitAction
{
    public function __construct(private readonly VisitorVisitQuery $visits) {}

    public function handle(
        ?string $token,
        string $browserHash,
        ?int $userId,
        ?int $gameId,
        ?int $versionId,
        VisitorSignals $signals,
        CarbonImmutable $now,
    ): ?int {
        if (! TrafficSetting::current()->capture_enabled) {
            return null;
        }

        $arrival = ArrivalToken::read($token, $browserHash, $now->getTimestamp());

        if ($arrival === null) {
            return null;
        }

        if (isset($arrival['visit'])) {
            return $this->open((int) $arrival['visit'], $browserHash, $gameId, $versionId, $signals, $now);
        }

        if (BotSignals::isCrawler($signals->botFlags)) {
            return null;
        }

        $gameId ??= isset($arrival['slug']) ? Game::query()->where('slug', $arrival['slug'])->value('id') : null;
        $gameId = $gameId === null ? null : (int) $gameId;

        $holding = $this->visits->holding($browserHash, $now);

        if ($holding !== null) {
            return $this->touch($holding, $gameId, $versionId, $now);
        }

        $recent = $this->visits->recentUnpaid($browserHash, $gameId, $now->subMinutes(30));

        if ($recent !== null) {
            return $this->touch($recent, $gameId, $versionId, $now);
        }

        $channel = TrafficChannel::tryFrom((string) ($arrival['channel'] ?? '')) ?? TrafficChannel::Direct;

        if ($channel->isPaid()) {
            $channel = TrafficChannel::Direct;
        }

        $known = $userId !== null ? TrafficKnownReason::SignedIn : ($this->visits->isKnownBrowser($browserHash) ? TrafficKnownReason::Browser : null);
        $prices = TrafficPriceSet::current();
        $group = $prices->groupOf($signals->location->countryCode);

        return DB::transaction(function () use ($channel, $arrival, $browserHash, $known, $signals, $gameId, $versionId, $prices, $group, $now): int {
            $visit = new TrafficVisit;
            $visit->forceFill([
                'channel' => $channel,
                'origin' => isset($arrival['origin']) ? mb_substr((string) $arrival['origin'], 0, 120) : null,
                'visitor_hash' => $browserHash,
                'known' => $known !== null,
                'known_reason' => $known,
                'device' => $signals->device,
                'country' => $signals->location->countryCode,
                'region' => $signals->location->region,
                'city' => $signals->location->city,
                'game_id' => $gameId,
                'version_id' => $versionId,
                'price_set_id' => $prices->id,
                'country_group' => $group['key'],
                'worth_percent' => $group['percent'],
                'bot_flags' => $signals->botFlags,
                'arrived_at' => $now,
                'network_day' => $now->setTimezone('UTC')->format('Y-m-d'),
                'last_seen_at' => $now,
                'opened_at' => $now,
                'spend' => '0',
                'spend_basis' => TrafficSpendBasis::None,
            ])->save();

            TrafficVisitDetail::query()->forceCreate([
                'visit_id' => $visit->id,
                'landing_path' => $signals->path,
                'ip_hash' => $signals->ipHash,
                'os' => $signals->os,
                'browser' => $signals->browser,
                'language' => $signals->language,
                'created_at' => $now,
            ]);

            return (int) $visit->id;
        });
    }

    private function open(int $visitId, string $browserHash, ?int $gameId, ?int $versionId, VisitorSignals $signals, CarbonImmutable $now): ?int
    {
        return DB::transaction(function () use ($visitId, $browserHash, $gameId, $versionId, $signals, $now): ?int {
            $visit = TrafficVisit::query()->whereKey($visitId)->where('visitor_hash', $browserHash)->lockForUpdate()->first();

            if ($visit === null) {
                return null;
            }

            $changes = ['last_seen_at' => $now, 'bot_flags' => $visit->bot_flags | ($signals->botFlags & BotSignals::HEADLESS)];

            if ($visit->opened_at === null && ! BotSignals::isCrawler($visit->bot_flags | $signals->botFlags)) {
                $changes['opened_at'] = $now;
            }

            if ($visit->game_id === null && $gameId !== null) {
                $changes['game_id'] = $gameId;
            }

            if ($visit->version_id === null && $versionId !== null && ($visit->game_id === null || $visit->game_id === $gameId)) {
                $changes['version_id'] = $versionId;
            }

            $visit->forceFill($changes)->save();

            return (int) $visit->id;
        });
    }

    /** A visit the page's steps go to, given the game and version it now shows when it had none. */
    private function touch(TrafficVisit $visit, ?int $gameId, ?int $versionId, CarbonImmutable $now): int
    {
        $changes = ['last_seen_at' => $now];

        if ($visit->game_id === null && $gameId !== null) {
            $changes['game_id'] = $gameId;
        }

        if ($visit->version_id === null && $versionId !== null && ($changes['game_id'] ?? $visit->game_id) === $gameId) {
            $changes['version_id'] = $versionId;
        }

        TrafficVisit::query()->whereKey($visit->id)->update($changes);

        return (int) $visit->id;
    }
}
