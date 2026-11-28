<?php

use App\Enums\AuthTrigger;
use App\Enums\GameEventType;
use App\Enums\GameOnwardKind;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\Post;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Models\User;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\PresenceChecks;
use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * While Record site visits is on, the small script of a page that is no game
 * hands its arrival back once the page is in a real browser: on the feed, on
 * a post, and on each page a wire:navigate move brings in with a head of its
 * own — every token once, however often its page is shown.
 */
beforeEach(function () {
    recordTraffic(site: true);

    // The arrival each "opened" signal hands back, in the order they come.
    $this->arrivals = new ArrayObject;
    $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event): void {
        if ($event->request->is('visit/signal') && $event->request->input('signal') === 'opened') {
            $this->arrivals[] = (string) $event->request->input('arrival');
        }
    });
});

/** The routes the arrivals handed back so far were printed on, read as the tracker reads them. */
function signalledRoutes(ArrayObject $arrivals): array
{
    $hash = TrafficVisit::query()->value('visitor_hash');

    return array_map(fn (string $token): ?string => ArrivalToken::read($token, (string) $hash, now()->getTimestamp())['route'] ?? null, $arrivals->getArrayCopy());
}

it('hands the arrival back on the feed, on a post and after a wire:navigate move, each once', function () {
    $author = User::factory()->create(['username' => 'beacon_author']);
    $post = Post::factory()->published()->for($author)->create(['title' => 'A post the beacon reads']);

    // As a desktop Chrome: a headless browser's own User-Agent reads as a crawler's, which leaves no unpaid visit.
    $page = visit('/')->withUserAgent(DESKTOP_CHROME_USER_AGENT);
    waitForScript($page, "document.querySelector('meta[name=\"rg-arrival\"]')?.dataset.sent === '1'");
    eventually(fn () => expect(TrafficVisit::query()->count())->toBe(1));

    expect(signalledRoutes($this->arrivals))->toBe(['feed'])
        ->and(TrafficVisit::query()->sole())->landing_key->toBe('feed')->game_id->toBeNull()->opened_at->not->toBeNull();

    navigatePageTo($page, route('posts.show', $post));
    eventually(fn () => expect(count($this->arrivals))->toBe(2));

    // The author's name moves to the profile by wire:navigate: no new document, a new head.
    $page->assertSee('A post the beacon reads');
    $page->script(<<<'JS'
        () => { setTimeout(() => document.querySelector('a[wire\\:navigate][href$="/u/beacon_author"]').click(), 0); return true; }
    JS);
    waitForScript($page, "location.pathname === '/u/beacon_author' && document.querySelector('[data-testid=\"profile-page\"]') !== null");
    eventually(fn () => expect(count($this->arrivals))->toBe(3));

    expect(signalledRoutes($this->arrivals))->toBe(['feed', 'posts.show', 'profile.show'])
        // One visit a session, whatever pages of the site it shows.
        ->and(TrafficVisit::query()->count())->toBe(1);

    // Back to the post: the page Livewire kept is shown again, with the token it already handed back.
    $page->script('() => { setTimeout(() => history.back(), 0); return true; }');
    waitForScript($page, "location.pathname === '/posts/{$post->id}'");
    // Half a second is far longer than the signal of a page already in takes to arrive.
    proveNothingHappensFor($page, 0.5, 'a second "opened" signal for the post');

    expect(count($this->arrivals))->toBe(3);
});

it('counts a post opened through its own page, and a copied link of it as its share', function () {
    $post = Post::factory()->published()->create(['title' => 'A post the reader shares']);

    $page = visit('/posts/'.$post->id)->withUserAgent(DESKTOP_CHROME_USER_AGENT);
    waitForScript($page, "document.querySelector('meta[name=\"rg-arrival\"]')?.dataset.sent === '1'");
    eventually(fn () => expect(TrafficVisitPost::query()->sole())->post_id->toBe($post->id)->views->toBe(1)->shared_at->toBeNull());

    // The copy button of the share dialog: the link is copied and the tracker told, the post naming itself.
    // A headless browser may not write to the clipboard: the write succeeds here as a person's browser lets it.
    $page->script("() => { Object.defineProperty(navigator.clipboard, 'writeText', { value: () => Promise.resolve() }); return true; }");
    $page->click('[data-testid="post-show-share-btn"]');
    $page->click('[data-testid="share-copy-link"]');
    waitForScript($page, "document.querySelector('[data-testid=\"copy-check\"]')?.offsetParent !== null");
    eventually(fn () => expect(TrafficVisitPost::query()->sole()->shared_at)->not->toBeNull());

    expect(TrafficVisit::query()->sole())->landing_key->toBe('posts.show');
});

it('puts the sign-up form a page shows on the visit that page makes, its signal waiting for the page’s own', function () {
    $page = visit('/register')->withUserAgent(DESKTOP_CHROME_USER_AGENT);
    waitForScript($page, "document.querySelector('meta[name=\"rg-arrival\"]')?.dataset.sent === '1'");

    eventually(fn () => expect(TrafficVisit::query()->sole())->game_id->toBeNull()->signup_form_at->not->toBeNull());
});

it('tells the tracker what opened the sign-in dialog, and that it showed the registration form', function () {
    $page = visit('/')->withUserAgent(DESKTOP_CHROME_USER_AGENT);
    waitForScript($page, "document.querySelector('meta[name=\"rg-arrival\"]')?.dataset.sent === '1'");
    eventually(fn () => expect(TrafficVisit::query()->sole()->opened_at)->not->toBeNull());

    $page->click('[data-testid="header-register-link"]');

    eventually(fn () => expect(TrafficVisit::query()->sole())
        ->auth_trigger->toBe(AuthTrigger::HeaderSignup)
        ->register_form_at->not->toBeNull()
        ->signup_form_at->not->toBeNull());
    $page->assertNoJavaScriptErrors();
});

it('names the post the sign-in dialog opens on, crediting the game that sent the browser there', function () {
    $post = Post::factory()->published()->create(['title' => 'A post a guest tries to vote on']);

    $page = visit('/posts/'.$post->id)->withUserAgent(DESKTOP_CHROME_USER_AGENT);
    waitForScript($page, "document.querySelector('meta[name=\"rg-arrival\"]')?.dataset.sent === '1'");
    $hash = eventually(fn () => expect(TrafficVisit::query()->sole()->visitor_hash)->toBeString()->value);
    // The play of this browser whose result sent it to the post a minute ago.
    $sent = GameAttempt::factory()->completed()->create(['visitor_hash' => $hash, 'onward_kind' => GameOnwardKind::Post, 'onward_post_id' => $post->id, 'onward_at' => now()->subMinute()]);

    // A guest's vote is refused, and the refusal opens the dialog.
    $page->click("[data-testid=\"post-upvote-button-{$post->id}\"]");

    eventually(fn () => expect(GameEvent::query()->where('type', GameEventType::PostSignInForm)->pluck('attempt_id')->all())->toBe([$sent->id]));
});

it('measures an arriving page in the browser: on screen, used, and what the headless browser it runs in gives away', function () {
    recordTraffic()->forceFill(['presence_enabled' => true])->save();
    TrafficSetting::forgetCaptureFlags();
    TrafficSource::factory()->create(['link_key' => 'exo']);

    $page = visit('/?rg_src=exo&cid=tag-presence')->withUserAgent(DESKTOP_CHROME_USER_AGENT);
    waitForScript($page, "document.querySelector('meta[name=\"rg-presence\"]') !== null");
    // A key the browser itself presses, which does nothing on the page. A bare 'body' would be read as text to find, and waited for.
    $page->keys('html > body', 'Shift');

    eventually(fn () => expect(TrafficVisit::query()->sole())->seen_at->not->toBeNull()->engaged_at->not->toBeNull());
    $visit = TrafficVisit::query()->sole();

    expect($visit->detail->presence)->toHaveKeys(['visible_at_start', 'to_input_ms', 'input_kind', 'graphics', 'platform', 'screen_width', 'window_width', 'time_zone'])
        ->and($visit->detail->presence['input_kind'])->toBe('key')
        // Playwright's Chromium says it is driven: WebDriver, or BotD naming it.
        ->and($visit->quality_flags & (PresenceChecks::WEBDRIVER | PresenceChecks::AUTOMATION))->not->toBe(0);
});
