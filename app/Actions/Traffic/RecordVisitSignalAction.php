<?php

namespace App\Actions\Traffic;

use App\Actions\Analytics\MarkAnalyticsDayAction;
use App\Actions\Analytics\RecordVisitPostAction;
use App\Actions\Games\CreditGameSiteActivityAction;
use App\Models\TrafficSetting;
use App\Models\TrafficVisit;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Capture\VisitorSignals;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What the small script on a page other than a game reports: that the page
 * ran (the arrival token it was given), that a sign-up form was shown to
 * the visitor — credited to the paid click holding the browser, else its
 * latest visit of the last day the tracker counts, else its latest visit of
 * that day, so a visit to the rest of the site never takes it from the game
 * visit Traffic sources counts it on; and, whether or not visits are
 * recorded, to the game play that sent the browser within 30 minutes to the
 * post whose page showed it (CreditGameSiteActivityAction), a failure of
 * which is reported and never spoils the signal — or that the visitor
 * shared a post by email, by copying its link or through the system's share
 * sheet, which goes on the visit the browser is on now
 * (RecordVisitPostAction). A crawler shares nothing.
 */
final class RecordVisitSignalAction
{
    public function __construct(
        private readonly OpenTrafficVisitAction $open,
        private readonly VisitorVisitQuery $visits,
        private readonly RecordVisitPostAction $posts,
        private readonly CreditGameSiteActivityAction $games,
        private readonly MarkAnalyticsDayAction $analytics,
    ) {}

    /** @param  int|null  $postId  the published post a `post_shared` signal names, or the post whose page showed a `signup_form` */
    public function handle(string $signal, ?string $token, string $browserHash, ?int $userId, VisitorSignals $signals, CarbonImmutable $now, ?int $postId = null): void
    {
        if ($signal === 'opened') {
            $this->open->handle($token, $browserHash, $userId, null, null, $signals, $now);

            return;
        }

        if ($signal === 'post_shared') {
            if ($postId !== null && ! BotSignals::isCrawler($signals->botFlags)) {
                $this->posts->shared($browserHash, $postId, $now);
            }

            return;
        }

        if ($signal !== 'signup_form') {
            return;
        }

        try {
            $this->games->signInFormShown($browserHash, $postId);
        } catch (Throwable $exception) {
            report($exception);
        }

        if (! TrafficSetting::captureFlags()['capture']) {
            return;
        }

        $visit = $this->visits->holding($browserHash, $now) ?? $this->visits->latestTrackedFirst($browserHash, $now->subDay());

        // The holding click can be days old: Analytics reads its day again.
        if ($visit !== null && $visit->signup_form_at === null && TrafficVisit::query()->whereKey($visit->id)->whereNull('signup_form_at')->update(['signup_form_at' => $now]) > 0) {
            $this->analytics->handle($visit->network_day, $now);
        }
    }
}
