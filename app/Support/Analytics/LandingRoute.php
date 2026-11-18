<?php

namespace App\Support\Analytics;

/**
 * The page a session entered by, as one key written on each of its visits
 * (`traffic_visits.landing_key`): the route's name, then the game and the
 * version the page showed, as far as they are known — `games.play:12:345`,
 * `games.result:12`, `posts.show`. A page whose route has no name is
 * `other`. Pure; every key fits the column's 60 characters.
 *
 * Read back, a key names where the page is (path()) — a game's page by the
 * game's slug, any other page by its route — and what a page without a game
 * is (page()).
 */
final class LandingRoute
{
    public const string OTHER = 'other';

    public const int MAX_LENGTH = 60;

    private const string PLAY = 'games.play';

    /** @var list<string> the pages that show a game's result: one a session began on was shared from it */
    private const array RESULTS = ['games.result', 'games.player-result'];

    /**
     * The pages without a game a session can begin on, by route: where each
     * is — a post's or profile's own part left out — and what it is.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array PAGES = [
        'feed' => ['/', 'Home feed'],
        'posts.show' => ['/posts/…', 'Post'],
        'profile.show' => ['/u/…', 'Profile'],
        'saved-posts.index' => ['/saved', 'Saved posts'],
        'pages.about' => ['/about', 'About'],
        'pages.privacy' => ['/privacy', 'Privacy'],
        'pages.terms' => ['/terms', 'Terms'],
        'pages.contact' => ['/contact', 'Contact'],
        'login' => ['/login', 'Sign in'],
        'register' => ['/register', 'Sign up'],
    ];

    public static function key(?string $route, ?int $gameId, ?int $versionId): string
    {
        $route = $route === null ? '' : (string) preg_replace('/[^A-Za-z0-9._-]/', '', $route);

        if ($route === '') {
            return self::OTHER;
        }

        $ids = $gameId === null ? '' : ':'.$gameId.($versionId === null ? '' : ':'.$versionId);

        return substr($route, 0, self::MAX_LENGTH - strlen($ids)).$ids;
    }

    /**
     * The key once the version a game's own page showed is known: a landing
     * on that game's page that had none yet gets it, any other stays as it is.
     */
    public static function withVersion(?string $key, int $gameId, int $versionId): ?string
    {
        return $key === self::PLAY.':'.$gameId ? self::key(self::PLAY, $gameId, $versionId) : $key;
    }

    /** @return array{route: string, gameId: int|null, versionId: int|null} what a key names */
    public static function parts(string $key): array
    {
        $parts = explode(':', $key);

        return [
            'route' => $parts[0],
            'gameId' => isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null,
            'versionId' => isset($parts[2]) && ctype_digit($parts[2]) ? (int) $parts[2] : null,
        ];
    }

    /**
     * Where a landing is: a game's page by the game's slug — “…” for a game
     * gone — a result shared from it marked so, any other page by its route,
     * a post's or profile's own part left out; a page it knows no path of is
     * one of the other pages.
     */
    public static function path(string $key, ?string $slug = null): string
    {
        $route = self::parts($key)['route'];
        $game = '/games/'.($slug ?? '…');

        return match (true) {
            $route === self::PLAY => $game,
            in_array($route, self::RESULTS, true) => $game.' · shared result',
            default => self::PAGES[$route][0] ?? 'Other pages',
        };
    }

    /** What a landing without a game is: “Home feed”, “Post”; a page it doesn't know, another page. */
    public static function page(string $key): string
    {
        return self::PAGES[self::parts($key)['route']][1] ?? 'Other page';
    }

    /** Whether a landing is one of a game's pages: the game itself or a result shared from it. */
    public static function showsGame(string $key): bool
    {
        $route = self::parts($key)['route'];

        return $route === self::PLAY || in_array($route, self::RESULTS, true);
    }
}
