<?php

namespace App\Support\Analytics;

/**
 * The page a session entered by, as one key written on each of its visits
 * (`traffic_visits.landing_key`): the route's name, then the game and the
 * version the page showed, as far as they are known — `games.play:12:345`,
 * `games.result:12`, `posts.show`. A page whose route has no name is
 * `other`. Pure; every key fits the column's 60 characters.
 */
final class LandingRoute
{
    public const string OTHER = 'other';

    public const int MAX_LENGTH = 60;

    private const string PLAY = 'games.play';

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
}
