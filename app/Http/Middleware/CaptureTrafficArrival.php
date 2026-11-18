<?php

namespace App\Http\Middleware;

use App\Actions\Traffic\RecordPaidClickAction;
use App\Models\Post;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Queries\Games\TestLinkVersionQuery;
use App\Support\Analytics\UtmTags;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Observability\DomainLogger;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\ChannelClassifier;
use App\Support\Traffic\Capture\RequestSignals;
use App\Support\Traffic\Capture\TrafficArrival;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Notices how a visitor arrived (docs/architecture/traffic.md). A request
 * for a page with a known rg_src records the paid click before the page is
 * drawn — before the guest-viewing check can send the visitor to sign in,
 * before a switched-off section answers 404 — and gives the browser its
 * cookie. A game page or a game's result page reached otherwise carries the
 * unpaid channel it was reached through, the referrer's host, the page's
 * own route and path and the campaign tags its address carries; that visit
 * is recorded only when the page's script runs. While Record site visits is
 * on too, so does a page of the site's own — the feed, a post, a profile,
 * the static pages, signing in and up, saved posts — asked for as a page,
 * and one that turns out to be none (a redirect, an error) takes its cookie
 * back. The administration, a game's preview, share redirects and the health
 * check are never among them, nor a game page opened by a valid test link to
 * one of its versions (VersionTestLink). A post's own page carries its post
 * as well, paid click or not, counted opened when the page's script runs.
 *
 * A page without rg_src that is neither costs nothing: no query, no cookie.
 * One that is reads the two switches, one read of the cache, and with
 * recording off costs nothing more. Whatever goes wrong here is reported and
 * the page is drawn as ever.
 */
final class CaptureTrafficArrival
{
    /** @var list<string> */
    private const array GAME_ROUTES = ['games.play', 'games.result', 'games.player-result'];

    /**
     * The game pages that show a result: one reached from outside the site is
     * a shared result.
     *
     * @var list<string>
     */
    private const array RESULT_ROUTES = ['games.result', 'games.player-result'];

    /** @var list<string> */
    private const array SITE_ROUTES = ['feed', 'posts.show', 'profile.show', 'pages.about', 'pages.privacy', 'pages.terms', 'pages.contact', 'login', 'register', 'saved-posts.index'];

    public function __construct(
        private readonly GameVisitorResolver $visitors,
        private readonly TrafficArrival $arrival,
        private readonly DomainLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $onSitePage = false;

        if ($request->isMethod('GET')) {
            try {
                $onSitePage = $this->notice($request);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $response = $next($request);

        if ($onSitePage && ! self::isPage($response)) {
            $this->takeBackCookie();
        }

        return $response;
    }

    /** Whether it gave an unpaid arrival on one of the site's own pages its token. */
    private function notice(Request $request): bool
    {
        $key = $request->query('rg_src');
        $route = $request->route()?->getName();
        $route = is_string($route) ? $route : null;
        $paid = is_string($key) && preg_match('/^[a-z0-9_-]{1,40}$/', $key) === 1;
        $onGame = in_array($route, self::GAME_ROUTES, true);
        $onSite = in_array($route, self::SITE_ROUTES, true);

        if (! $paid && ! $onGame && ! $onSite) {
            return false;
        }

        // A game's test link plays its version and records nothing: no click, no arrival, no cookie.
        if ($route === 'games.play' && $request->query->has('v') && is_string($request->route('slug'))
            && app(TestLinkVersionQuery::class)->find($request, $request->route('slug')) !== null) {
            return false;
        }

        $flags = TrafficSetting::captureFlags();

        // Nothing is recorded while recording is off, so no visitor is given the tracker's cookie for it.
        if (! $flags['capture']) {
            return false;
        }

        if ($paid && $this->recordClick($request, (string) $key, $route)) {
            return false;
        }

        if (! $onGame && ! ($onSite && $flags['site'] && $request->acceptsHtml())) {
            return false;
        }

        $referrer = $request->headers->get('referer');
        $referrer = is_string($referrer) ? $referrer : null;
        $landsOnResult = in_array($route, self::RESULT_ROUTES, true);
        $found = ChannelClassifier::classify(
            $referrer,
            (string) $request->getHost(),
            $landsOnResult,
            $this->routeOf($referrer, (string) $request->getHost()),
            $request->userAgent(),
        );
        $host = ChannelClassifier::host($referrer);
        // A host is plain ASCII, punycode included; anything else a bot sends would only swell the token past what is read back.
        $host = $host !== null && preg_match('/^[\x21-\x7E]{1,190}$/', $host) === 1 ? $host : null;
        $tags = UtmTags::fromQuery($request->query())->toArray();

        // The page itself, for the visit and its details: the call that hands the token back is made from another address.
        $this->arrival->set(ArrivalToken::make([
            'channel' => $found['channel']->value,
            'origin' => $found['origin'],
            'slug' => $onGame && is_string($request->route('slug')) ? $request->route('slug') : null,
            'result' => $landsOnResult,
            'route' => $route,
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
            'host' => $host,
            ...self::postOf($request, $route),
            ...($tags === [] ? [] : ['tags' => $tags]),
        ], $this->visitors->ensureBrowserHash(), CarbonImmutable::now('UTC')->getTimestamp()));

        return ! $onGame;
    }

    /**
     * The post a post's own page shows, for its token: the post is counted
     * opened when the page's script hands the token back.
     *
     * @return array{post?: int}
     */
    private static function postOf(Request $request, ?string $route): array
    {
        if ($route !== 'posts.show') {
            return [];
        }

        $post = $request->route('post');
        $id = $post instanceof Post ? (int) $post->getKey() : (is_string($post) && preg_match('/^[1-9][0-9]{0,17}$/', $post) === 1 ? (int) $post : 0);

        return $id > 0 ? ['post' => $id] : [];
    }

    /** Whether the response is a page drawn for a browser, which prints the token for its script. */
    private static function isPage(Response $response): bool
    {
        return $response->isSuccessful() && str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html');
    }

    /** A site page that turned out to be no page prints no token, so a browser it gave a cookie to keeps none. */
    private function takeBackCookie(): void
    {
        if ($this->visitors->issuedNow()) {
            Cookie::unqueue((string) config('games.visitor_cookie.name'));
        }
    }

    /** Whether a paid click was recorded (and its token handed to the page). */
    private function recordClick(Request $request, string $key, ?string $route): bool
    {
        $source = TrafficSource::query()->where('link_key', $key)->first();

        if ($source === null) {
            $this->unknown($key);

            return false;
        }

        $hash = $this->visitors->ensureBrowserHash();
        $now = CarbonImmutable::now('UTC');
        $visit = app(RecordPaidClickAction::class)->handle(
            $source,
            $request->query(),
            $hash,
            $this->visitors->issuedNow(),
            $request->user()?->getAuthIdentifier() === null ? null : (int) $request->user()->getAuthIdentifier(),
            $route,
            in_array($route, self::GAME_ROUTES, true) && is_string($request->route('slug')) ? $request->route('slug') : null,
            app(RequestSignals::class)->read(),
            $now,
        );

        if ($visit === null) {
            return false;
        }

        $this->arrival->set(ArrivalToken::make(['visit' => (int) $visit->id, ...self::postOf($request, $route)], $hash, $now->getTimestamp()));

        return true;
    }

    /** The name of the route a referrer on this site points at. */
    private function routeOf(?string $referrer, string $host): ?string
    {
        if ($referrer === null || ChannelClassifier::host($referrer) !== ChannelClassifier::host('https://'.$host)) {
            return null;
        }

        try {
            return Route::getRoutes()->match(Request::create((string) parse_url($referrer, PHP_URL_PATH) ?: '/'))->getName();
        } catch (Throwable) {
            return null;
        }
    }

    private function unknown(string $key): void
    {
        $limit = 'traffic-unknown-source:'.$key;

        if (RateLimiter::tooManyAttempts($limit, 1)) {
            return;
        }

        RateLimiter::hit($limit, 600);
        $this->logger->warning('traffic.click.unknown_source', ['link_key' => $key]);
    }
}
