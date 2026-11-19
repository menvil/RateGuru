<?php

use App\Models\Post;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Models\User;
use App\Support\Traffic\Capture\ArrivalToken;
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
