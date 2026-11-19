<?php

use App\Actions\Analytics\RecordUserActivityAction;
use App\Actions\Analytics\RecordVisitPostAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\UserActivity;
use App\Livewire\Comments\CommentForm;
use App\Livewire\Comments\CommentsSection;
use App\Livewire\Feed\FeedPage;
use App\Livewire\Feed\PostDrawer;
use App\Livewire\Posts\PostVoting;
use App\Livewire\Voting\RatingVoting;
use App\Models\AccountAttribution;
use App\Models\Comment;
use App\Models\Game;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Models\User;
use App\Models\UserActivityDay;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The posts a browser opens and shares, and what a member does on them, go on
 * the visit the browser is on: the paid click holding it, else the visit it
 * was seen on last within half an hour. A post is opened through its own page
 * (its id in the arrival token), the feed's detail column and every overlay —
 * one view a request — and shared through the site's own redirect or the
 * page's script, once a visit. A crawler, a browser without a visit and a
 * switched-off tracker write nothing.
 */
const VISIT_POSTS_TOKEN = 'postViewerApostViewerApostViewerApostVie';

const CRAWLER_USER_AGENT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-18 12:00:00');
    recordTraffic(site: true);
    $this->hash = browserHashOf(VISIT_POSTS_TOKEN);
    $this->post = Post::factory()->published()->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** The browser's unpaid visit to the feed, seen the given minutes ago. */
function feedVisitOf(string $hash, int $minutesAgo = 5): TrafficVisit
{
    $at = now()->subMinutes($minutesAgo);

    return TrafficVisit::factory()->unpaid()->create(['visitor_hash' => $hash, 'arrived_at' => $at, 'last_seen_at' => $at, 'opened_at' => $at, 'landing_key' => 'feed']);
}

/** A component of the feed, drawn for the test browser, a desktop Chrome. */
function viewerComponent(string $component, array $parameters = []): Testable
{
    return Livewire::withCookie((string) config('games.visitor_cookie.name'), VISIT_POSTS_TOKEN)
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT, 'Accept-Language' => 'en'])
        ->test($component, $parameters);
}

function postsOpenInTheOverlay(bool $overlay): void
{
    $settings = ProjectSettings::query()->find(1) ?? ProjectSettings::factory()->create();
    $settings->forceFill(['feature_flags' => [...($settings->feature_flags ?? []), 'post_detail_overlay_mode' => $overlay]])->save();
    app(ProjectSettingsManager::class)->flush();
}

function visitPostSignal(array $data, string $agent = DESKTOP_CHROME_USER_AGENT): TestResponse
{
    return test()->withCookie((string) config('games.visitor_cookie.name'), VISIT_POSTS_TOKEN)
        ->withHeaders(['User-Agent' => $agent, 'Accept-Language' => 'en'])
        ->post(route('traffic.signal'), $data);
}

/** The token a page printed for its script, as it would hand it back. */
function arrivalPrintedBy(TestResponse $page): string
{
    preg_match('/<meta name="rg-arrival" content="([^"]+)">/', (string) $page->getContent(), $meta);

    return html_entity_decode($meta[1] ?? '');
}

it('counts a post opened through its own page once the page runs, on the visit the page made', function () {
    $token = arrivalPrintedBy(clickOn('/posts/'.$this->post->id, VISIT_POSTS_TOKEN)->assertOk());

    expect(ArrivalToken::read($token, $this->hash, now()->getTimestamp()))->post->toBe($this->post->id)
        ->and(TrafficVisitPost::query()->exists())->toBeFalse();

    visitPostSignal(['signal' => 'opened', 'arrival' => $token])->assertNoContent();

    $visit = TrafficVisit::query()->sole();
    expect(TrafficVisitPost::query()->sole())
        ->visit_id->toBe($visit->id)
        ->post_id->toBe($this->post->id)
        ->views->toBe(1)
        ->shared_at->toBeNull()
        ->first_at->toEqual(now());
});

it('counts a post a paid click landed on, on that click', function () {
    TrafficSource::factory()->create(['link_key' => 'exo']);
    $token = arrivalPrintedBy(clickOn('/posts/'.$this->post->id.'?rg_src=exo', VISIT_POSTS_TOKEN)->assertOk());

    visitPostSignal(['signal' => 'opened', 'arrival' => $token])->assertNoContent();

    $click = TrafficVisit::query()->sole();
    expect($click->source_id)->not->toBeNull()
        ->and(TrafficVisitPost::query()->sole())->visit_id->toBe($click->id)->views->toBe(1);
});

it('counts a post’s own page as it is drawn while Record site visits is off, on the visit the browser is on', function () {
    recordTraffic();
    $game = TrafficVisit::factory()->unpaid()->create(['visitor_hash' => $this->hash, 'game_id' => Game::factory()->create()->id, 'arrived_at' => now()->subMinutes(5), 'last_seen_at' => now()->subMinutes(5)]);

    $page = clickOn('/posts/'.$this->post->id.'?vote=1', VISIT_POSTS_TOKEN)->assertOk();

    expect($page->getContent())->not->toContain('rg-arrival')
        ->and(TrafficVisitPost::query()->sole())->visit_id->toBe($game->id)->views->toBe(1)
        // A game visit is seen only by its own pages.
        ->and($game->fresh()->last_seen_at->toDateTimeString())->toBe('2026-11-18 11:55:00');
});

it('keeps a visit to the feed seen while its visitor reads, so a game opened later goes on with its session', function () {
    postsOpenInTheOverlay(true);
    $feed = TrafficVisit::factory()->unpaid()->create(['visitor_hash' => $this->hash, 'arrived_at' => now()->subMinutes(20), 'last_seen_at' => now()->subMinutes(20), 'source_key' => 'reddit:organic', 'landing_key' => 'feed']);
    $feed->forceFill(['entry_visit_id' => $feed->id])->save();
    $later = Post::factory()->published()->create();

    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $this->post->id);
    CarbonImmutable::setTestNow('2026-11-18 12:20:00');
    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $later->id);

    expect(TrafficVisitPost::query()->where('visit_id', $feed->id)->count())->toBe(2)
        ->and($feed->fresh()->last_seen_at->toDateTimeString())->toBe('2026-11-18 12:20:00');
});

it('counts a post opened from the feed’s detail column in the split view on a desktop', function () {
    postsOpenInTheOverlay(false);
    $visit = feedVisitOf($this->hash);

    viewerComponent(FeedPage::class)->call('selectPost', $this->post->id)->assertDispatched('post-selected');

    expect(TrafficVisitPost::query()->sole())->visit_id->toBe($visit->id)->views->toBe(1);
});

it('counts a post opened in the split view’s panel on a phone', function () {
    postsOpenInTheOverlay(false);
    $visit = feedVisitOf($this->hash);

    viewerComponent(PostDrawer::class, ['asOverlay' => true, 'mobileOnly' => true])->call('setSelectedPost', $this->post->id);

    expect(TrafficVisitPost::query()->sole())->visit_id->toBe($visit->id)->views->toBe(1);
});

it('counts a post opened in the overlay once, though the feed answers the same click', function () {
    postsOpenInTheOverlay(true);
    feedVisitOf($this->hash);

    // Both answer one click: the feed marks the card, the overlay shows the post and counts it.
    viewerComponent(FeedPage::class)->call('selectPost', $this->post->id);
    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $this->post->id);

    expect(TrafficVisitPost::query()->sole()->views)->toBe(1);
});

it('never counts a post twice in one request, and counts it again when it is opened again', function () {
    postsOpenInTheOverlay(true);
    feedVisitOf($this->hash);
    $overlay = viewerComponent(PostDrawer::class, ['asOverlay' => true]);
    $open = ['method' => 'setSelectedPost', 'params' => [$this->post->id], 'path' => ''];

    $overlay->update(calls: [$open, $open]);

    expect(TrafficVisitPost::query()->sole()->views)->toBe(1);

    $overlay->call('setSelectedPost', $this->post->id);

    expect(TrafficVisitPost::query()->sole()->views)->toBe(2);
});

it('counts nothing for a crawler, a browser without a visit of the last half hour, or a post its page would not show', function () {
    postsOpenInTheOverlay(true);
    $visit = feedVisitOf($this->hash, minutesAgo: 2);
    $pending = Post::factory()->create(['status' => PostStatus::Pending]);

    // First: the cookie a test gives Livewire stays for the rest of the test.
    Livewire::test(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $this->post->id);
    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $pending->id);

    expect(TrafficVisitPost::query()->exists())->toBeFalse();

    // Livewire's test requests after the first keep no headers of their own: the crawler's is put on as the route is found.
    Event::listen(RouteMatched::class, fn (RouteMatched $matched) => $matched->request->headers->set('User-Agent', CRAWLER_USER_AGENT));
    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $this->post->id);
    Event::forget(RouteMatched::class);

    expect(TrafficVisitPost::query()->exists())->toBeFalse();

    // A crawler's own visit — a paid click its robot followed — takes no post either.
    $robot = TrafficVisit::factory()->create(['visitor_hash' => $this->hash, 'bot_flags' => BotSignals::CRAWLER]);
    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $this->post->id);

    expect(TrafficVisitPost::query()->exists())->toBeFalse();

    $robot->delete();

    $visit->forceFill(['last_seen_at' => now()->subMinutes(31)])->save();
    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $this->post->id);

    expect(TrafficVisitPost::query()->exists())->toBeFalse();

    // A crawler that runs a post's own page leaves no visit and no view.
    $token = ArrivalToken::make(['channel' => 'direct', 'route' => 'posts.show', 'path' => '/posts/'.$this->post->id, 'post' => $this->post->id], $this->hash, now()->getTimestamp());
    visitPostSignal(['signal' => 'opened', 'arrival' => $token], CRAWLER_USER_AGENT)->assertNoContent();

    expect(TrafficVisitPost::query()->exists())->toBeFalse()
        ->and(TrafficVisit::query()->count())->toBe(1);
});

it('puts a post on the paid click holding the browser before a later visit', function () {
    $click = TrafficVisit::factory()->create(['visitor_hash' => $this->hash, 'arrived_at' => now()->subHours(3), 'last_seen_at' => now()->subHours(3)]);
    feedVisitOf($this->hash, minutesAgo: 1);

    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $this->post->id);

    expect(TrafficVisitPost::query()->sole()->visit_id)->toBe($click->id);
});

it('counts a share through the site’s redirect once a visit, and none for a crawler', function () {
    $visit = feedVisitOf($this->hash);
    $share = fn (string $agent = DESKTOP_CHROME_USER_AGENT): TestResponse => $this->withCookie((string) config('games.visitor_cookie.name'), VISIT_POSTS_TOKEN)
        ->withHeaders(['User-Agent' => $agent])
        ->get(route('posts.share', ['post' => $this->post->id, 'provider' => 'facebook']));

    $share(CRAWLER_USER_AGENT)->assertRedirect();

    expect(TrafficVisitPost::query()->exists())->toBeFalse();

    $share()->assertRedirect();
    CarbonImmutable::setTestNow('2026-11-18 12:03:00');
    $share()->assertRedirect();

    expect(TrafficVisitPost::query()->sole())
        ->visit_id->toBe($visit->id)
        ->views->toBe(0)
        ->shared_at->toEqual(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

it('counts a share by email, a copied link or the share sheet from the page’s script, for a published post only', function () {
    $visit = feedVisitOf($this->hash);
    $pending = Post::factory()->create(['status' => PostStatus::Pending]);

    visitPostSignal(['signal' => 'post_shared', 'post' => $pending->id])->assertSessionHasErrors('post');
    visitPostSignal(['signal' => 'post_shared', 'post' => 999_999])->assertSessionHasErrors('post');
    visitPostSignal(['signal' => 'post_shared'])->assertSessionHasErrors('post');
    visitPostSignal(['signal' => 'post_shared', 'post' => $this->post->id], CRAWLER_USER_AGENT)->assertNoContent();

    expect(TrafficVisitPost::query()->exists())->toBeFalse();

    visitPostSignal(['signal' => 'post_shared', 'post' => $this->post->id])->assertNoContent();
    CarbonImmutable::setTestNow('2026-11-18 12:04:00');
    visitPostSignal(['signal' => 'post_shared', 'post' => $this->post->id])->assertNoContent();

    expect(TrafficVisitPost::query()->sole())
        ->visit_id->toBe($visit->id)
        ->shared_at->toEqual(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

it('counts a member’s new vote on the visit, and not one taken back or turned', function () {
    $member = User::factory()->create();
    $visit = feedVisitOf($this->hash);
    $vote = fn (Post $post, string $type) => Livewire::withCookie((string) config('games.visitor_cookie.name'), VISIT_POSTS_TOKEN)
        ->actingAs($member)->test(PostVoting::class, ['postId' => $post->id])->call('vote', $type);

    $vote($this->post, 'up');
    $vote($this->post, 'up');

    expect($visit->fresh()->votes)->toBe(1)
        ->and(TrafficVisitPost::query()->sole())->post_id->toBe($this->post->id)->voted->toBeTrue()->views->toBe(0);

    // Turned the other way, the vote is taken back: no new vote.
    $vote($this->post, 'down');

    expect(PostVote::query()->where('post_id', $this->post->id)->exists())->toBeFalse()
        ->and($visit->fresh()->votes)->toBe(1);

    $vote($this->post, 'up');
    $group = RatingGroup::factory()->create(['key' => 'type']);
    [$first, $second] = [RatingOption::factory()->for($group, 'group')->create(), RatingOption::factory()->for($group, 'group')->create()];
    $rate = fn (int $optionId) => Livewire::withCookie((string) config('games.visitor_cookie.name'), VISIT_POSTS_TOKEN)
        ->actingAs($member)->test(RatingVoting::class, ['post' => $this->post, 'groupKey' => 'type'])->call('vote', $optionId);
    $rate($first->id);
    $rate($second->id);

    // A vote cast again after it was taken back is new; an answer changed is not.
    expect($visit->fresh()->votes)->toBe(3)
        ->and(TrafficVisitPost::query()->count())->toBe(1);
});

it('counts a member’s comments and replies on the visit, and marks the post commented', function () {
    $member = User::factory()->create();
    $visit = feedVisitOf($this->hash);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), VISIT_POSTS_TOKEN)->actingAs($member)
        ->test(CommentForm::class, ['postId' => $this->post->id])->set('body', 'A comment')->call('submit')->assertHasNoErrors();
    $comment = Comment::factory()->for($this->post)->create(['status' => CommentStatus::Visible]);
    Livewire::withCookie((string) config('games.visitor_cookie.name'), VISIT_POSTS_TOKEN)->actingAs($member)
        ->test(CommentsSection::class, ['postId' => $this->post->id])
        ->call('startReply', $comment->id)->set('replyBody', 'A reply')->call('submitReply')->assertHasNoErrors();

    expect($visit->fresh())->comments->toBe(2)->votes->toBe(0)
        ->and(TrafficVisitPost::query()->sole())->commented->toBeTrue()->voted->toBeFalse();
});

it('counts a member’s posts on the visit, without a row for the new post', function () {
    $member = User::factory()->create();
    $visit = feedVisitOf($this->hash);

    app(RecordUserActivityAction::class)->handle($member, UserActivity::Posted, $this->post->id, $this->hash, CarbonImmutable::now());
    app(RecordUserActivityAction::class)->handle($member, UserActivity::Played, null, $this->hash, CarbonImmutable::now());

    expect($visit->fresh())->posts_created->toBe(1)->votes->toBe(0)->comments->toBe(0)
        ->and(TrafficVisitPost::query()->exists())->toBeFalse();
});

it('writes nothing on a visit while visits are not recorded, the member’s day staying', function () {
    $member = User::factory()->create();
    AccountAttribution::factory()->create(['user_id' => $member->id, 'signed_up_at' => now()->subDay()]);
    postsOpenInTheOverlay(true);
    $visit = feedVisitOf($this->hash);
    recordTraffic(capture: false);

    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $this->post->id);
    visitPostSignal(['signal' => 'post_shared', 'post' => $this->post->id])->assertNoContent();
    $this->withCookie((string) config('games.visitor_cookie.name'), VISIT_POSTS_TOKEN)->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])
        ->get(route('posts.share', ['post' => $this->post->id, 'provider' => 'facebook']))->assertRedirect();
    Livewire::withCookie((string) config('games.visitor_cookie.name'), VISIT_POSTS_TOKEN)
        ->actingAs($member)->test(PostVoting::class, ['postId' => $this->post->id])->call('vote', 'up');

    expect(TrafficVisitPost::query()->exists())->toBeFalse()
        ->and($visit->fresh()->votes)->toBe(0)
        ->and(UserActivityDay::query()->where('user_id', $member->id)->sole()->voted)->toBeTrue();
});

it('begins a post voted on before it was opened at the moment it was first opened, and keeps that moment', function () {
    $visit = feedVisitOf($this->hash);
    $posts = app(RecordVisitPostAction::class);

    $posts->actedOn($visit->id, $this->post->id, UserActivity::Voted, CarbonImmutable::parse('2026-11-18 12:01:00'));
    $posts->viewed($this->hash, $this->post->id, CarbonImmutable::parse('2026-11-18 12:03:00'));

    expect(TrafficVisitPost::query()->sole())->views->toBe(1)->voted->toBeTrue()->first_at->toEqual(CarbonImmutable::parse('2026-11-18 12:03:00'));

    $posts->viewed($this->hash, $this->post->id, CarbonImmutable::parse('2026-11-18 12:05:00'));

    expect(TrafficVisitPost::query()->sole())->views->toBe(2)->first_at->toEqual(CarbonImmutable::parse('2026-11-18 12:03:00'));
});

it('keeps one row a visit and post however the requests race, every engine taking the insert that finds it there', function () {
    $visit = feedVisitOf($this->hash);
    TrafficVisitPost::query()->forceCreate(['visit_id' => $visit->id, 'post_id' => $this->post->id, 'views' => 4, 'first_at' => now()->subMinutes(3)]);

    viewerComponent(PostDrawer::class, ['asOverlay' => true])->call('setSelectedPost', $this->post->id);
    app(RecordUserActivityAction::class)->handle(User::factory()->create(), UserActivity::Commented, $this->post->id, $this->hash, CarbonImmutable::now());

    expect(TrafficVisitPost::query()->sole())
        ->views->toBe(5)
        ->commented->toBeTrue()
        ->first_at->toEqual(now()->subMinutes(3));
});
