<?php

namespace App\Actions\Traffic;

use App\Models\TrafficSetting;
use App\Models\TrafficVisit;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Traffic\Capture\VisitorSignals;
use Carbon\CarbonImmutable;

/**
 * What the small script on a page other than a game reports: that the page
 * ran (the arrival token it was given), or that a sign-up form was shown to
 * the visitor — credited to the paid click holding the browser, else its
 * visit of the last day.
 */
final class RecordVisitSignalAction
{
    public function __construct(
        private readonly OpenTrafficVisitAction $open,
        private readonly VisitorVisitQuery $visits,
    ) {}

    public function handle(string $signal, ?string $token, string $browserHash, ?int $userId, VisitorSignals $signals, CarbonImmutable $now): void
    {
        if ($signal === 'opened') {
            $this->open->handle($token, $browserHash, $userId, null, null, $signals, $now);

            return;
        }

        if ($signal !== 'signup_form' || ! TrafficSetting::current()->capture_enabled) {
            return;
        }

        $visit = $this->visits->holding($browserHash, $now) ?? $this->visits->latest($browserHash, $now->subDay());

        if ($visit !== null && $visit->signup_form_at === null) {
            TrafficVisit::query()->whereKey($visit->id)->whereNull('signup_form_at')->update(['signup_form_at' => $now]);
        }
    }
}
