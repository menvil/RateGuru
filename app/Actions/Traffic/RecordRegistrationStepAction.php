<?php

namespace App\Actions\Traffic;

use App\Enums\AuthTrigger;
use App\Enums\SignupRefusal;
use App\Models\TrafficSetting;
use App\Models\TrafficVisit;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;

/**
 * The registration steps between the sign-in dialog and an account, each put
 * once on the visit the browser is on: the paid click holding it, else the
 * visit it was seen on last within the session (VisitorVisitQuery::current)
 * — while visits are recorded and the visit is no crawler's. The first
 * moment of each step stays, and the first opener of the dialog and the
 * first provider gone on to with it; every reason a sign-up was refused is
 * kept (SignupRefusal, a bit each), and a refused sign-up was a sent one.
 * Counted only: no conversion, no postback, no change of worth, and the
 * visit is never seen again for it, so a paid click settles as it would.
 * The visit's day is marked for Analytics with the write, a click days old
 * included — all of it by MarkTrafficVisitAction, the one writer of a
 * visit's marks (docs/architecture/traffic.md, "Registration steps").
 */
final class RecordRegistrationStepAction
{
    public function __construct(
        private readonly VisitorVisitQuery $visits,
        private readonly MarkTrafficVisitAction $marks,
    ) {}

    /** The sign-in dialog shown — opened by the trigger on its first showing of the visit — and, in its sign-up state, the registration form. */
    public function dialogShown(string $browserHash, bool $registering, AuthTrigger $trigger, CarbonImmutable $now): void
    {
        $this->mark($this->visitOf($browserHash, $now), [
            'auth_opened_at' => ['auth_trigger' => $trigger->value],
            ...($registering ? ['register_form_at' => []] : []),
        ], $now);
    }

    /** A game's own sign-up form shown, on the game's visit: opened by that form. */
    public function formShownOn(int $visitId, CarbonImmutable $now): void
    {
        $visit = TrafficSetting::captureFlags()['capture'] ? TrafficVisit::query()->find($visitId) : null;

        $this->mark($visit !== null && ! BotSignals::isCrawler($visit->bot_flags) ? $visit : null, [
            'auth_opened_at' => ['auth_trigger' => AuthTrigger::GameForm->value],
            'register_form_at' => [],
        ], $now);
    }

    /** Continue with Google or Facebook pressed: a sign-up or a sign-in, the provider's answer tells which. */
    public function socialStarted(?string $browserHash, string $provider, CarbonImmutable $now): void
    {
        $this->mark($this->visitOf($browserHash, $now), ['social_started_at' => ['social_provider' => mb_substr($provider, 0, 16)]], $now);
    }

    /** The email sign-up form sent, whatever comes of it. */
    public function submitted(?string $browserHash, CarbonImmutable $now): void
    {
        $this->mark($this->visitOf($browserHash, $now), ['signup_submitted_at' => []], $now);
    }

    /**
     * A sign-up form sent and refused, for every reason given.
     *
     * @param  list<SignupRefusal>  $refusals
     */
    public function refused(?string $browserHash, array $refusals, CarbonImmutable $now): void
    {
        $this->mark($this->visitOf($browserHash, $now), ['signup_submitted_at' => [], 'signup_refused_at' => []], $now, SignupRefusal::mask($refusals));
    }

    private function visitOf(?string $browserHash, CarbonImmutable $now): ?TrafficVisit
    {
        if ($browserHash === null || $browserHash === '' || ! TrafficSetting::captureFlags()['capture']) {
            return null;
        }

        $visit = $this->visits->current($browserHash, $now, $now->subMinutes((int) config('analytics.session_minutes')));

        return $visit === null || BotSignals::isCrawler($visit->bot_flags) ? null : $visit;
    }

    /**
     * Each moment set where the visit has none yet, with what goes with it,
     * the reasons added to those kept — by the one writer of a visit's marks.
     *
     * @param  array<string, array<string, string>>  $moments  the moment's column, and the columns written with it
     */
    private function mark(?TrafficVisit $visit, array $moments, CarbonImmutable $now, int $refusals = 0): void
    {
        if ($visit !== null) {
            $this->marks->registrationSteps((int) $visit->id, $moments, $refusals, $now);
        }
    }
}
