<?php

namespace App\Actions\Analytics;

use App\Enums\UserActivity;
use App\Models\TrafficSetting;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Queries\Posts\PublishedPostDetailsQuery;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A post a browser opened or shared, on the visit it belongs to: the paid
 * click holding the browser, else the visit it was seen on last within
 * `analytics.session_minutes` (VisitorVisitQuery::current()). With no such
 * visit, while visits aren't recorded, or for a crawler's visit, nothing is
 * written; a visit to the rest of the site it goes on is seen again, so its
 * session lasts while the visitor reads without loading a page. A post is opened through its own page (OpenTrafficVisitAction,
 * from the arrival token), the feed's detail column and the overlays
 * (CountsPostViews); shared through a provider's redirect
 * (SharePostController) or the page's script (`post_shared`).
 *
 * The visit's row of the post (`traffic_visit_posts`) is made once,
 * whichever request makes it first, on every engine: an insert that skips a
 * row already there, then a change of that row alone — each view adds one,
 * the first share sets `shared_at` and later ones leave it, a vote or a
 * comment sets its flag (RecordUserActivityAction).
 */
final class RecordVisitPostAction
{
    /** The most a count of the row or of the visit goes to: what a small integer holds on every engine. */
    public const int MOST = 32767;

    public function __construct(
        private readonly VisitorVisitQuery $visits,
        private readonly PublishedPostDetailsQuery $posts,
    ) {}

    /** The browser opened the post, which its own page would show. */
    public function viewed(string $browserHash, int $postId, CarbonImmutable $now): void
    {
        $visit = $this->visitFor($browserHash, $now);

        if ($visit !== null && $this->posts->isPublished($postId)) {
            $this->view((int) $visit->id, $postId, $now);
        }
    }

    /**
     * The browser opened the post's own page, whose run was credited to the
     * given visit — the visit is the browser's and its page was drawn, so
     * the post was published.
     */
    public function viewedOn(int $visitId, int $postId, CarbonImmutable $now): void
    {
        $flags = TrafficVisit::query()->whereKey($visitId)->value('bot_flags');

        if ($flags !== null && ! BotSignals::isCrawler((int) $flags)) {
            $this->view($visitId, $postId, $now);
        }
    }

    /** The browser shared the post, which its own page would show; a second share on the visit changes nothing. */
    public function shared(string $browserHash, int $postId, CarbonImmutable $now): void
    {
        $visit = $this->visitFor($browserHash, $now);

        if ($visit === null) {
            return;
        }

        $this->row((int) $visit->id, $postId, $now);
        $this->rowOf((int) $visit->id, $postId)->whereNull('shared_at')->update(['shared_at' => $now]);
    }

    /** A member voted on the post or commented on it on the given visit: the flag of its row. */
    public function actedOn(int $visitId, int $postId, UserActivity $activity, CarbonImmutable $now): void
    {
        $flag = $activity->postFlag();

        if ($flag === null) {
            return;
        }

        $this->row($visitId, $postId, $now);
        $this->rowOf($visitId, $postId)->where($flag, false)->update([$flag => true]);
    }

    /**
     * The visit what the browser does now belongs to, while visits are
     * recorded and it is no crawler's; null when there is none.
     */
    public function visitFor(string $browserHash, CarbonImmutable $now): ?TrafficVisit
    {
        if (! TrafficSetting::captureFlags()['capture']) {
            return null;
        }

        $visit = $this->visits->current($browserHash, $now, $now->subMinutes((int) config('analytics.session_minutes')));

        if ($visit === null || BotSignals::isCrawler($visit->bot_flags)) {
            return null;
        }

        // What the browser does on a page of the rest of the site keeps that visit seen, so its session goes on while
        // the visitor reads without loading another page. A visit the tracker counts — a paid click settling, a game
        // visit a later arrival may come back to — is seen only as it always was.
        if (! $visit->isTracked()) {
            TrafficVisit::query()->whereKey($visit->id)->where('last_seen_at', '<', $now)->update(['last_seen_at' => $now]);
        }

        return $visit;
    }

    private function view(int $visitId, int $postId, CarbonImmutable $now): void
    {
        $this->row($visitId, $postId, $now);
        $this->rowOf($visitId, $postId)->where('views', '<', self::MOST)->increment('views');
    }

    /** The visit's row of the post, made with nothing counted yet unless a request made it first. */
    private function row(int $visitId, int $postId, CarbonImmutable $now): void
    {
        TrafficVisitPost::query()->insertOrIgnore([[
            'visit_id' => $visitId,
            'post_id' => $postId,
            'views' => 0,
            'shared_at' => null,
            'voted' => false,
            'commented' => false,
            'first_at' => $now->utc(),
        ]]);
    }

    /** @return Builder<TrafficVisitPost> */
    private function rowOf(int $visitId, int $postId): Builder
    {
        return TrafficVisitPost::query()->where('visit_id', $visitId)->where('post_id', $postId);
    }
}
