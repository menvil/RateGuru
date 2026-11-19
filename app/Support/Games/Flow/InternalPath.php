<?php

namespace App\Support\Games\Flow;

/**
 * Whether a path a Go to Page step names is a page of this site a visitor can
 * be sent to: a local path (no scheme, no host, not protocol-relative) whose
 * route is the feed, a post, a profile, a game or a static page. The route
 * name is read by the caller (GameFlowFactsQuery, LeaveGameAction).
 */
final class InternalPath
{
    /** @var list<string> */
    private const array ROUTES = ['feed', 'posts.show', 'profile.show', 'games.play', 'pages.about', 'pages.privacy', 'pages.terms', 'pages.contact'];

    public static function isLocal(string $path): bool
    {
        return preg_match('#^/(?!/)[^\\\\\s]*$#', $path) === 1 && ! str_contains($path, "\0") && parse_url($path, PHP_URL_HOST) === null;
    }

    public static function allows(?string $routeName): bool
    {
        return $routeName !== null && in_array($routeName, self::ROUTES, true);
    }
}
