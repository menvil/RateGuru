<?php

namespace App\Http\Middleware;

use App\Actions\Traffic\RecordPaidClickAction;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Observability\DomainLogger;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\ChannelClassifier;
use App\Support\Traffic\Capture\RequestSignals;
use App\Support\Traffic\Capture\TrafficArrival;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
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
 * unpaid channel it was reached through; that visit is recorded only when
 * the page's script runs. A page without rg_src that is no game costs
 * nothing: no query, no cookie. Whatever goes wrong here is reported and the
 * page is drawn as ever.
 */
final class CaptureTrafficArrival
{
    /** @var list<string> */
    private const array GAME_ROUTES = ['games.play', 'games.result', 'games.player-result'];

    public function __construct(
        private readonly GameVisitorResolver $visitors,
        private readonly TrafficArrival $arrival,
        private readonly DomainLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            try {
                $this->notice($request);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $next($request);
    }

    private function notice(Request $request): void
    {
        $key = $request->query('rg_src');
        $route = $request->route()?->getName();
        $onGame = is_string($route) && in_array($route, self::GAME_ROUTES, true);

        if (is_string($key) && preg_match('/^[a-z0-9_-]{1,40}$/', $key) === 1) {
            if ($this->recordClick($request, $key, $route)) {
                return;
            }
        } elseif (! $onGame) {
            return;
        }

        if (! $onGame) {
            return;
        }

        $referrer = $request->headers->get('referer');
        $found = ChannelClassifier::classify(
            is_string($referrer) ? $referrer : null,
            (string) $request->getHost(),
            $route !== 'games.play',
            $this->routeOf($referrer, (string) $request->getHost()),
            $request->userAgent(),
        );

        $this->arrival->set(ArrivalToken::make([
            'channel' => $found['channel']->value,
            'origin' => $found['origin'],
            'slug' => is_string($request->route('slug')) ? $request->route('slug') : null,
        ], $this->visitors->ensureBrowserHash(), CarbonImmutable::now('UTC')->getTimestamp()));
    }

    /** Whether a paid click was recorded (and its token handed to the page). */
    private function recordClick(Request $request, string $key, ?string $route): bool
    {
        if (! TrafficSetting::current()->capture_enabled) {
            return false;
        }

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
            $route === 'games.play' && is_string($request->route('slug')) ? $request->route('slug') : null,
            app(RequestSignals::class)->read(),
            $now,
        );

        if ($visit === null) {
            return false;
        }

        $this->arrival->set(ArrivalToken::make(['visit' => (int) $visit->id], $hash, $now->getTimestamp()));

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
