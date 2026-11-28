<?php

namespace App\Actions\Traffic;

use App\Actions\Analytics\MarkAnalyticsDayAction;
use App\Actions\Analytics\RecordVisitPostAction;
use App\Enums\TrafficChannel;
use App\Enums\TrafficEvent;
use App\Enums\TrafficKnownReason;
use App\Enums\TrafficSpendBasis;
use App\Enums\UserRole;
use App\Models\Game;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\User;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Services\Traffic\TrafficVisitWorth;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\LandingRoute;
use App\Support\Analytics\SessionRule;
use App\Support\Analytics\UtmTags;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Capture\VisitorSignals;
use App\Support\Traffic\Postbacks\MilestoneContext;
use App\Support\Traffic\Worth\VisitValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The page ran in a real browser: the first call its script makes hands back
 * the arrival token, and the visit it names is marked opened — or, for an
 * unpaid arrival, the visit is recorded now, never on the request for the
 * page, so a crawler that runs no script leaves nothing behind. While a paid
 * click still holds the browser, an unpaid return is that click's: no new
 * visit, and the game's steps go to the click. A signed-in member of staff
 * or persona leaves no unpaid visit; their paid clicks count as anyone's.
 *
 * An unpaid arrival either continues the browser's session (SessionRule):
 * the same visit again on the same game or page, else a new visit carrying
 * the session's first visit and where that one came from, its tags included
 * — or begins a session of its own, the visit being its own first: its
 * source by the referrer and the tags (utm_source, the class of utm_medium),
 * its campaign by utm_campaign, its creative by utm_content or else
 * utm_term, and its landing named by the page's route, game and version.
 * Tags of its own other than the session's begin a new session. The page's
 * own path, referrer and tags come in the token, since the call that hands
 * it back is made from another address. Returns the visit the page's steps
 * are credited to, if any.
 *
 * A page that shows no game — the feed, a post, a profile, the site's own
 * pages — records its visit only while Record site visits is on too, and
 * such a visit is worth nothing: the tracker doesn't count it
 * (TrafficVisit::isTracked()), so Traffic sources and the worth stay as the
 * games and paid clicks make them. For the same reason such a page leaves
 * the paid click holding the browser untouched.
 *
 * A post's own page counts its post opened on the visit it went to
 * (RecordVisitPostAction), unless a crawler ran it; a failure to count it is
 * reported and never loses the visit.
 */
final class OpenTrafficVisitAction
{
    public function __construct(
        private readonly VisitorVisitQuery $visits,
        private readonly TrafficVisitWorth $worth,
        private readonly MarkTrafficVisitAction $marks,
        private readonly RecordVisitPostAction $posts,
        private readonly MarkAnalyticsDayAction $analytics,
    ) {}

    public function handle(
        ?string $token,
        string $browserHash,
        ?int $userId,
        ?int $gameId,
        ?int $versionId,
        VisitorSignals $signals,
        CarbonImmutable $now,
    ): ?int {
        $flags = TrafficSetting::captureFlags();

        if (! $flags['capture']) {
            return null;
        }

        $arrival = ArrivalToken::read($token, $browserHash, $now->getTimestamp());

        if ($arrival === null) {
            return null;
        }

        $visitId = $this->credit($arrival, $flags, $browserHash, $userId, $gameId, $versionId, $signals, $now);

        // A post's own page ran: the post was opened, on the visit the page went to.
        if ($visitId !== null && isset($arrival['post']) && ! BotSignals::isCrawler($signals->botFlags)) {
            try {
                $this->posts->viewedOn($visitId, (int) $arrival['post'], $now);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $visitId;
    }

    /**
     * The visit the arriving page's steps are credited to, recorded or opened
     * as the arrival asks.
     *
     * @param  array{visit?: int, channel?: string, origin?: string|null, slug?: string|null, result?: bool, route?: string|null, path?: string, host?: string|null, post?: int, tags?: array<string, string>}  $arrival
     * @param  array{capture: bool, site: bool}  $flags
     */
    private function credit(array $arrival, array $flags, string $browserHash, ?int $userId, ?int $gameId, ?int $versionId, VisitorSignals $signals, CarbonImmutable $now): ?int
    {
        if (isset($arrival['visit'])) {
            return $this->open((int) $arrival['visit'], $browserHash, $gameId, $versionId, $signals, $now);
        }

        if (BotSignals::isCrawler($signals->botFlags)) {
            return null;
        }

        $gameId ??= isset($arrival['slug']) ? Game::query()->where('slug', $arrival['slug'])->value('id') : null;
        $gameId = $gameId === null ? null : (int) $gameId;

        // A page without a game handed back after Record site visits was switched off.
        if ($gameId === null && ! $flags['site']) {
            return null;
        }

        $holding = $this->visits->holding($browserHash, $now);

        // The paid click holding the browser, met first on a later game page — its own landing sent the visitor to sign in — is opened here.
        // A page without a game leaves it as it was: neither opened by it nor kept from settling, so its network hears what it always did.
        if ($holding !== null) {
            if ($gameId === null) {
                return (int) $holding->id;
            }

            return $holding->opened_at === null
                ? $this->open((int) $holding->id, $browserHash, $gameId, $versionId, $signals, $now)
                : $this->touch($holding, $gameId, $versionId, $now);
        }

        // Staff checking the site leave no unpaid visit; the paid click holding their browser, above, is credited as anyone's.
        if ($userId !== null && $this->isStaff($userId)) {
            return null;
        }

        $channel = TrafficChannel::tryFrom((string) ($arrival['channel'] ?? '')) ?? TrafficChannel::Direct;

        if ($channel->isPaid()) {
            $channel = TrafficChannel::Direct;
        }

        $minutes = (int) config('analytics.session_minutes');
        $since = $now->subMinutes($minutes);
        $tags = UtmTags::fromQuery(is_array($arrival['tags'] ?? null) ? $arrival['tags'] : []);
        $sourceKey = AnalyticsSource::keyFor($channel, null, isset($arrival['host']) ? (string) $arrival['host'] : null, $tags->source, $tags->medium);
        $latest = $this->visits->lastSeenUnpaid($browserHash, $since);

        // A game page is first matched against the browser's visits to games alone, as the tracker
        // matched it before the rest of the site was recorded: a visit to the feed in between never
        // splits off a second visit to a game Traffic sources counts once.
        $latestGame = $gameId === null || $latest === null || $latest->game_id !== null ? null : $this->visits->lastSeenUnpaid($browserHash, $since, gamesOnly: true);

        if ($latestGame !== null
            && SessionRule::continues($channel, (bool) ($arrival['result'] ?? false), $tags->differFrom(self::tagsOf($latestGame)), $latestGame->last_seen_at, $now, $minutes)
            && ($same = $this->sessionVisitTo($latestGame, $browserHash, $gameId, $since)) !== null) {
            return $this->touch($same, $gameId, $versionId, $now);
        }

        if ($latest !== null && SessionRule::continues($channel, (bool) ($arrival['result'] ?? false), $tags->differFrom(self::tagsOf($latest)), $latest->last_seen_at, $now, $minutes)) {
            $same = $this->sessionVisitTo($latest, $browserHash, $gameId, $since);

            return $same !== null
                ? $this->touch($same, $gameId, $versionId, $now)
                : $this->record($arrival, $channel, $sourceKey, $tags, $browserHash, $userId, $gameId, $versionId, $signals, $latest, $now);
        }

        $again = $this->visits->recentUnpaid($browserHash, $gameId, $since);

        // Only a page a session entered by can be reloaded from where it came; a page reached inside
        // the site carries its session's source, not how it was reached.
        if ($again !== null && $again->entry_visit_id === $again->id
            && SessionRule::repeats($sourceKey, $again->source_key, ! $tags->equals(self::tagsOf($again)), $again->last_seen_at, $now, $minutes)) {
            return $this->touch($again, $gameId, $versionId, $now);
        }

        return $this->record($arrival, $channel, $sourceKey, $tags, $browserHash, $userId, $gameId, $versionId, $signals, null, $now);
    }

    /**
     * A new unpaid visit: of the session the given visit is in, carrying where
     * that session came from, or — with none — the first of its own.
     *
     * @param  array{visit?: int, channel?: string, origin?: string|null, slug?: string|null, result?: bool, route?: string|null, path?: string, host?: string|null, post?: int, tags?: array<string, string>}  $arrival
     */
    private function record(
        array $arrival,
        TrafficChannel $channel,
        string $sourceKey,
        UtmTags $tags,
        string $browserHash,
        ?int $userId,
        ?int $gameId,
        ?int $versionId,
        VisitorSignals $signals,
        ?TrafficVisit $session,
        CarbonImmutable $now,
    ): int {
        $known = $userId !== null ? TrafficKnownReason::SignedIn : ($this->visits->isKnownBrowser($browserHash) ? TrafficKnownReason::Browser : null);
        $prices = TrafficPriceSet::current();
        $group = $prices->groupOf($signals->location->countryCode);
        $entry = $session === null
            ? [
                'source_key' => $sourceKey,
                'campaign_key' => CampaignKey::tag($tags->campaign),
                'creative_key' => $tags->creative(),
                'landing_key' => LandingRoute::key(isset($arrival['route']) ? (string) $arrival['route'] : null, $gameId, $versionId),
                'landing_post_id' => isset($arrival['post']) ? (int) $arrival['post'] : null,
                ...$tags->columns(),
            ]
            : [
                'entry_visit_id' => $session->entry_visit_id ?? $session->id,
                'source_key' => $session->source_key,
                'campaign_key' => $session->campaign_key,
                'creative_key' => $session->creative_key,
                'landing_key' => $session->landing_key,
                'landing_post_id' => $session->landing_post_id,
                ...self::tagsOf($session)->columns(),
            ];

        return DB::transaction(function () use ($channel, $arrival, $browserHash, $known, $signals, $gameId, $versionId, $prices, $group, $entry, $now): int {
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
                'asn' => $signals->network->asn,
                'arrived_at' => $now,
                'network_day' => $now->setTimezone('UTC')->format('Y-m-d'),
                'last_seen_at' => $now,
                'opened_at' => $now,
                'spend' => '0',
                'spend_basis' => TrafficSpendBasis::None,
                ...$entry,
            ])->save();

            TrafficVisitDetail::query()->forceCreate([
                'visit_id' => $visit->id,
                'landing_path' => isset($arrival['path']) ? mb_substr((string) $arrival['path'], 0, 255) : $signals->path,
                'referrer_host' => isset($arrival['host']) ? mb_substr((string) $arrival['host'], 0, 190) : null,
                'ip_hash' => $signals->ipHash,
                'os' => $signals->os,
                'browser' => $signals->browser,
                'language' => $signals->language,
                'created_at' => $now,
            ]);

            $values = $visit->isTracked() ? $this->worth->values($visit, $now) : VisitValue::nothing();
            $visit->forceFill([
                // A visit beginning its session is its own first, now that it has an id.
                'entry_visit_id' => $visit->entry_visit_id ?? $visit->id,
                'value_real' => $values['real'],
                'value_expected' => $values['expected'],
            ])->save();

            return (int) $visit->id;
        });
    }

    /**
     * The visit of the session that shows the arriving page: the session's
     * latest when it shows the same game (or, like the arrival, none), else
     * the session's latest visit to that game, if it had one.
     */
    private function sessionVisitTo(TrafficVisit $latest, string $browserHash, ?int $gameId, CarbonImmutable $since): ?TrafficVisit
    {
        if ($latest->game_id === $gameId) {
            return $latest;
        }

        return $this->visits->recentUnpaid($browserHash, $gameId, $since, (int) ($latest->entry_visit_id ?? $latest->id));
    }

    /** The campaign tags a visit keeps for its session. */
    private static function tagsOf(TrafficVisit $visit): UtmTags
    {
        return UtmTags::fromQuery($visit->only(UtmTags::KEYS));
    }

    /** Whether the signed-in account is one whose unpaid visits aren't counted: staff and personas. */
    private function isStaff(int $userId): bool
    {
        $role = User::query()->whereKey($userId)->value('role');

        return $role instanceof UserRole && $role !== UserRole::User;
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
                $game = $changes['game_id'] ?? $visit->game_id;

                // A click that landed on this game's page: its landing now knows the version shown.
                if ($game !== null && $visit->entry_visit_id === $visit->id) {
                    $changes['landing_key'] = LandingRoute::withVersion($visit->landing_key, $game, $versionId);
                }
            }

            $visit->forceFill($changes)->save();

            // A click opened, given its game or seen again days after it came: Analytics reads its day again.
            $this->analytics->handle($visit->network_day, $now);

            // A paid click that turned out to run in a browser: a step its
            // network can be told of, worth what opening the page is worth.
            if (isset($changes['opened_at'])) {
                $values = $visit->isTracked() ? $this->worth->values($visit, $now) : VisitValue::nothing();
                $visit->forceFill(['value_real' => $values['real'], 'value_expected' => $values['expected']])->save();
                $this->marks->convert($visit, TrafficEvent::Opened, $now, new MilestoneContext(gameId: $visit->game_id, versionId: $visit->version_id));
            }

            return (int) $visit->id;
        });
    }

    /**
     * A visit the page's steps go to, seen now unless it was seen later
     * already, and given the game and version it now shows when it had none:
     * only while it still has none, so a game another tab gave it since it
     * was read is never replaced.
     */
    private function touch(TrafficVisit $visit, ?int $gameId, ?int $versionId, CarbonImmutable $now): int
    {
        $given = TrafficVisit::query()->whereKey($visit->id)->where('last_seen_at', '<', $now)->update(['last_seen_at' => $now]);

        if ($visit->game_id === null && $gameId !== null) {
            $given += TrafficVisit::query()->whereKey($visit->id)->whereNull('game_id')->update(['game_id' => $gameId]);
        }

        if ($visit->version_id === null && $versionId !== null && ($visit->game_id ?? $gameId) === $gameId) {
            $given += TrafficVisit::query()->whereKey($visit->id)->where('game_id', $gameId)->whereNull('version_id')->update(['version_id' => $versionId]);
        }

        // The holding click can be days old: Analytics reads its day again once it is seen again or shows a game.
        if ($given > 0) {
            $this->analytics->handle($visit->network_day, $now);
        }

        return (int) $visit->id;
    }
}
