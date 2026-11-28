<?php

namespace App\Actions\Traffic;

use App\Models\TrafficSetting;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Capture\PresenceChecks;
use App\Support\Traffic\Capture\PresenceFacts;
use App\Support\Traffic\Capture\VisitorSignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a landing page's script measured of its visitor (PresenceFacts), put on
 * the visit the page arrived on: the paid click its token names, else the
 * browser's visit seen last within half an hour (VisitorVisitQuery::lastSeen())
 * — a game page's, whose player took the token, or a site page's — never an
 * older paid click that only still holds the browser. When the page
 * was first on screen and first touched, clicked, typed or scrolled are marked
 * once, at the first report that says so; what contradicts what the browser
 * says it is (PresenceChecks) is added to the visit's quality flags; and the
 * facts are kept on its details, a later report of the same page merged over
 * the first (PresenceFacts::over()). Only while landing pages may measure
 * (TrafficSetting::captureFlags()['presence']); a crawler's report is no one's.
 */
final readonly class RecordVisitPresenceAction
{
    /** How long after its last request a visit still takes its page's report. */
    private const int CURRENT_MINUTES = 30;

    public function __construct(private VisitorVisitQuery $visits) {}

    public function handle(?string $token, string $browserHash, PresenceFacts $presence, VisitorSignals $signals, ?string $userAgent, CarbonImmutable $now): void
    {
        if (! TrafficSetting::captureFlags()['presence'] || BotSignals::isCrawler($signals->botFlags)) {
            return;
        }

        $arrival = ArrivalToken::read($token, $browserHash, $now->getTimestamp());
        $visit = isset($arrival['visit'])
            ? TrafficVisit::query()->whereKey((int) $arrival['visit'])->where('visitor_hash', $browserHash)->first()
            : $this->visits->lastSeen($browserHash, $now->subMinutes(self::CURRENT_MINUTES));

        if ($visit === null) {
            return;
        }

        $flags = PresenceChecks::flags($presence, $signals->location->countryCode, $visit->device, $userAgent);

        DB::transaction(function () use ($visit, $presence, $flags, $now): void {
            $locked = TrafficVisit::query()->whereKey($visit->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $locked->forceFill(array_filter([
                'seen_at' => $locked->seen_at ?? ($presence->seen() ? $now : null),
                'engaged_at' => $locked->engaged_at ?? ($presence->engaged() ? $now : null),
            ]) + ['quality_flags' => $locked->quality_flags | $flags])->save();

            $detail = TrafficVisitDetail::query()->find($locked->id);

            if ($detail !== null) {
                $detail->forceFill(['presence' => $presence->over($detail->presence ?? [])])->save();
            }
        });
    }
}
