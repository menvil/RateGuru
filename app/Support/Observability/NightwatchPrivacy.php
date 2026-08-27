<?php

namespace App\Support\Observability;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Command;
use Laravel\Nightwatch\Records\Exception;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\Request;

/**
 * Every decision RateGuru makes about what a Nightwatch event may contain.
 *
 * Each public method here is registered as one official Nightwatch callback by
 * App\Providers\ObservabilityServiceProvider and does nothing else — the
 * methods are the callbacks, which is what lets the tests hand a real
 * Nightwatch record to the same code the agent path runs, rather than
 * reflecting into the package or asserting on its source.
 *
 * The baseline this has to meet is config/sentry.php's, which is already
 * accepted and running on staging: no name, no email, no username, no precise
 * IP address, no Authorization or Cookie header, no request payload and no SQL
 * bindings. Nightwatch's defaults are broader than Sentry's in three of those
 * places — it captures `name` and `email` for the authenticated user, the
 * request IP, and the full request URL including its query string — so those
 * three are closed here rather than assumed.
 *
 * This is deliberately not a vendor-neutral abstraction. Sentry is configured
 * through Sentry's own API and Nightwatch through Nightwatch's; the two
 * products are being compared, and one of them is expected to be removed.
 */
final class NightwatchPrivacy
{
    private const REDACTED = '[redacted]';

    /**
     * Route parameters whose *value* must never be transmitted, even though it
     * sits in the path rather than the query string.
     *
     * `password.reset` puts a live reset token in the path and the account's
     * email in the query; `verification.verify` puts the user ID, a hash of
     * the email and a live HMAC signature there. For a route whose URI names
     * one of these, the URI pattern is transmitted instead of the concrete
     * path — so `/reset-password/{token}` is what Nightwatch sees, which is
     * the part with diagnostic value anyway.
     *
     * `username` is here for a different reason. It is not a secret — `/u/…`
     * is a public page — but it identifies a person, RateGuru treats it that
     * way everywhere else (account deletion tombstones it, `LogContext::base()`
     * deliberately omits it), and `Nightwatch::user()` is already suppressing
     * it. Sending the same handle back in the URL would make that suppression
     * theatre.
     *
     * @var list<string>
     */
    private const SENSITIVE_ROUTE_PARAMETERS = ['token', 'hash', 'signature', 'email', 'username'];

    /**
     * Query-string parameter names that may be transmitted, without their
     * values.
     *
     * A real closed allowlist, not a shape check. RateGuru's own query
     * vocabulary is this short — the feed filters, the profile tab and
     * pagination — and a parameter name is still attacker-controlled text, so
     * matching "looks like an identifier" would happily forward
     * `?<whatever-a-bot-put-here>`. Anything not named here is dropped whole,
     * including the framework's own `expires`/`signature`/`email` markers,
     * whose presence the route pattern already implies.
     *
     * @var list<string>
     */
    private const ALLOWED_QUERY_PARAMETERS = [
        // App\Livewire\Feed\FeedPage
        'search', 'tag', 'category', 'ratings', 'sort', 'feed',
        // App\Livewire\Profile\ProfilePage
        'tab',
        // Laravel pagination
        'page',
    ];

    /**
     * Artisan options whose value is personal data.
     *
     * `rateguru:admin:create` is the one RateGuru command that takes any, and
     * it is exactly the one an operator runs by hand on a live server.
     *
     * @var list<string>
     */
    private const REDACTED_COMMAND_OPTIONS = ['email', 'username', 'name'];

    /**
     * Cache keys RateGuru is willing to transmit, as an allowlist.
     *
     * An allowlist rather than a blocklist because of one concrete fact:
     * Illuminate\Cache\RateLimiter does not hash the keys it is handed (only
     * the ThrottleRequests middleware does, before calling it), and
     * App\Actions\Auth\AuthenticateUserAction's login throttle key is
     * literally "<email>|<ip>". A blocklist would have to anticipate that; an
     * allowlist cannot miss it, and any cache key shape added to RateGuru
     * later stays invisible to Nightwatch until someone deliberately adds it
     * here.
     *
     * @var list<string>
     */
    private const ALLOWED_CACHE_KEYS = [
        // App\Providers\AppServiceProvider — constant keys, no interpolation.
        '/^sidebar-nav-categories$/',
        '/^sidebar-nav-top-tags$/',

        // App\Jobs\RunMediaAuditJob — one global lock, constant key.
        '/^media-audit:full$/',

        // App\Services\Media\MediaLifecycleService — integer asset ID.
        '/^media-purge:[0-9]+$/',

        // App\Services\Media\MediaVariantWriter — integer asset ID plus a
        // MediaVariantName enum value.
        '/^media-variant-write:[0-9]+:[a-z0-9_]+$/',

        // App\Support\AbuseGuards\RateLimitKey — integer user ID plus an
        // action slug, optionally plus a target type and ID. Laravel appends
        // ':timer' to the companion key it writes for each limiter.
        '/^rate-limit:[a-z0-9_-]+:user:[0-9]+(:target:[a-z0-9_-]+:[0-9]+)?(:timer)?$/',
    ];

    /**
     * The maximum identity RateGuru ever attaches to a Nightwatch event.
     *
     * Nightwatch's default resolver captures `id`, `name` (User::$name) and
     * `username` (User::$email). Returning an empty array leaves only the ID —
     * the package documents the ID as always captured, and the internal
     * database ID is exactly the identity Sentry already carries, so the two
     * products stay comparable without either of them learning who the user
     * is.
     *
     * @return array<string, mixed>
     */
    public function userDetails(Authenticatable $user): array
    {
        return [];
    }

    /**
     * Removes the request IP and everything in the URL that is not scheme,
     * host, path or route.
     */
    public function redactRequest(Request $record): void
    {
        // Not hashed, not truncated to a /24, not replaced with a stable
        // pseudonym: Phase 6B has no question that a client address answers,
        // and a reversible-by-lookup stand-in is not anonymity. Sentry runs
        // with send_default_pii off for the same reason.
        $record->ip = '';

        $record->url = $this->sanitizeIncomingUrl($record->url, $record->routePath);
    }

    /**
     * Removes everything after the path from an outgoing request URL.
     *
     * Unlike an incoming URL, nothing here is RateGuru's vocabulary: the only
     * outbound requests RateGuru makes are to user-pasted import URLs and the
     * redirect hops those resolve to, so both the parameter names and their
     * values are arbitrary third-party strings — presigned-URL credentials,
     * share tokens, tracking identifiers. The scheme, host and path are what
     * an import failure is diagnosed from, and they are kept in full.
     *
     * The fragment goes too. `UrlImportValidator` strips it from what RateGuru
     * fetches, but a redirect `Location` or any other client is not bound by
     * that, and `#access_token=…` is a real shape on the public internet.
     */
    public function redactOutgoingRequest(OutgoingRequest $record): void
    {
        $record->url = $this->withoutQueryOrFragment($record->url);
    }

    /**
     * Drops every cache event whose key is not on the allowlist above.
     *
     * @return bool true to reject the event
     */
    public function rejectCacheEvent(CacheEvent $record): bool
    {
        foreach (self::ALLOWED_CACHE_KEYS as $pattern) {
            if (preg_match($pattern, $record->key) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes the values of Artisan options known to carry personal data.
     *
     * Nightwatch records the invocation string, so the values are removed
     * rather than the command being suppressed — knowing that an admin account
     * was created, and how long it took, is the useful part.
     *
     * Both option forms are covered, because Symfony Console accepts both and
     * an operator typing the command by hand is as likely to write
     * `--email someone@example.com` as `--email=someone@example.com`. The
     * value is consumed up to the next `--option` or the end of the string
     * rather than to the next space: Nightwatch builds this string by joining
     * the raw argv tokens with spaces, so a quoted multi-word value like
     * `--name "Ada Lovelace"` arrives indistinguishable from two tokens, and
     * stopping at the first space would leak the second word.
     */
    public function redactCommand(Command $record): void
    {
        $options = implode('|', self::REDACTED_COMMAND_OPTIONS);

        $record->command = (string) preg_replace(
            '/(--(?:'.$options.'))([=\s])(?:(?!\s--).)*/i',
            '$1$2'.self::REDACTED,
            $record->command,
        );
    }

    /**
     * Removes the offending value from a unique-constraint violation message.
     *
     * Almost every exception message RateGuru produces is static text, but a
     * driver-level unique-violation is not: PostgreSQL appends
     * `DETAIL: Key (email)=(someone@example.com) already exists.` and MySQL
     * `Duplicate entry 'someone@example.com' for key '...'`. Registration and
     * profile updates can both raise one, so the column name is kept — that is
     * the diagnosis — and the value is not.
     */
    public function redactException(Exception $record): void
    {
        $message = (string) preg_replace(
            '/(Key \([^)]*\)=\()[^)]*(\))/',
            '$1'.self::REDACTED.'$2',
            $record->message,
        );

        $record->message = (string) preg_replace(
            "/(Duplicate entry )'[^']*'/",
            "$1'".self::REDACTED."'",
            $message,
        );
    }

    /**
     * scheme://host[:port] + path, with the query reduced to the allowlisted
     * parameter names.
     *
     * Names without values are kept because they carry the one thing the
     * evaluation needs from a feed URL — which filters were in play — while
     * `?search=<free text typed by a user, frequently another user's name>` is
     * exactly what must not leave the server.
     */
    public function sanitizeIncomingUrl(string $url, string $routePath): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return '';
        }

        $origin = '';

        if (isset($parts['scheme'], $parts['host'])) {
            $origin = $parts['scheme'].'://'.$parts['host'];

            if (isset($parts['port'])) {
                $origin .= ':'.$parts['port'];
            }
        }

        $path = $this->routeNamesASensitiveParameter($routePath)
            ? $routePath
            : ($parts['path'] ?? '');

        $names = $this->queryParameterNames($parts['query'] ?? '');

        return $origin.$path.($names === '' ? '' : '?'.$names);
    }

    private function withoutQueryOrFragment(string $url): string
    {
        $cut = strcspn($url, '?#');

        return substr($url, 0, $cut);
    }

    private function routeNamesASensitiveParameter(string $routePath): bool
    {
        if ($routePath === '') {
            return false;
        }

        if (preg_match_all('/\{([A-Za-z0-9_]+)\??\}/', $routePath, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $parameter) {
            if (in_array(strtolower($parameter), self::SENSITIVE_ROUTE_PARAMETERS, true)) {
                return true;
            }
        }

        return false;
    }

    private function queryParameterNames(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $names = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = urldecode(strstr($pair, '=', true) ?: $pair);

            // `category[0]=…` is the same filter as `category=…`; the index
            // carries nothing and would multiply the name.
            $name = (string) preg_replace('/\[.*$/', '', $name);

            // Not on the allowlist: a scanner, a third-party redirect or a
            // framework marker the route pattern already implies. Dropped
            // whole — the name itself is attacker-controlled text.
            if (! in_array($name, self::ALLOWED_QUERY_PARAMETERS, true)) {
                continue;
            }

            $names[$name] = true;
        }

        $unique = array_keys($names);
        sort($unique);

        return implode('&', $unique);
    }
}
