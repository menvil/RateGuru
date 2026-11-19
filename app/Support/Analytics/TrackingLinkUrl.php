<?php

namespace App\Support\Analytics;

/**
 * How a tracking link's address is made, as the reference builds it: the
 * path it leads to on the site — its own parameters kept, any utm_ ones it
 * carried replaced — and the five campaign tags after them, each encoded,
 * an empty one left out; what the drawer's sources and mediums are; what a
 * tag may be; how a destination typed by hand is read; and the name a link
 * takes until the administrator names it. Pure: the site's origin and host
 * are handed in.
 */
final class TrackingLinkUrl
{
    /** @var array<string, string> the sources the drawer offers, by the utm_source each writes; any other is typed as Other / Custom */
    public const array SOURCES = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'reddit' => 'Reddit', 'google' => 'Google', 'email' => 'Email'];

    /** What the drawer's Other / Custom source is called. */
    public const string OTHER_SOURCE = 'other';

    /** @var list<string> the mediums a link can carry, each set apart from its source */
    public const array MEDIUMS = ['paid_social', 'organic_social', 'paid_search', 'organic_search', 'email', 'referral'];

    /** The longest utm_source and utm_medium a link keeps. */
    public const int MAX_SOURCE = 64;

    /** The longest utm_campaign, utm_content and utm_term a link keeps: as long as a visit keeps them (UtmTags). */
    public const int MAX_TAG = UtmTags::MAX_LENGTH;

    public const int MAX_PATH = 255;

    public const int MAX_NAME = 160;

    /** What a source and a campaign are written as: lower-case letters, digits, - and _, a letter or digit first. */
    private const string TOKEN = '/^[a-z0-9][a-z0-9_-]*$/';

    /**
     * The address of a link: the site's origin, the path, and the tags after
     * whatever else the path asks.
     *
     * @param  string  $origin  the site's scheme and host, e.g. https://rateguru.com
     * @param  array<string, string|null>  $tags  by their parameter names (UtmTags::KEYS)
     */
    public static function compose(string $origin, string $path, array $tags): string
    {
        [$route, $query] = self::split($path);
        $parameters = self::kept($query);

        foreach (UtmTags::KEYS as $key) {
            $value = $tags[$key] ?? null;

            if ($value !== null && $value !== '') {
                $parameters[] = $key.'='.rawurlencode($value);
            }
        }

        return rtrim($origin, '/').$route.($parameters === [] ? '' : '?'.implode('&', $parameters));
    }

    /** A path as a link keeps it: any utm_ parameter and the part after # gone. */
    public static function withoutTags(string $path): string
    {
        [$route, $query] = self::split($path);
        $parameters = self::kept($query);

        return $route.($parameters === [] ? '' : '?'.implode('&', $parameters));
    }

    /** The path of a game's page. */
    public static function gamePath(string $slug): string
    {
        return '/games/'.$slug;
    }

    /**
     * A destination typed by hand, read as the reference reads it: a path
     * of the site starting with /, or a full https:// address on its host;
     * kept as its path without utm_ parameters. Anything else is what is
     * wrong with it.
     *
     * @param  string  $host  the site's host, e.g. rateguru.com
     * @return array{path: string|null, error: string|null}
     */
    public static function customPath(string $raw, string $host): array
    {
        $raw = trim($raw);
        $wrong = fn (string $error): array => ['path' => null, 'error' => $error];

        if ($raw === '') {
            return $wrong('Enter a destination.');
        }

        if (preg_match('/\s/u', $raw) === 1) {
            return $wrong('Invalid URL: remove the spaces.');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw) === 1) {
            $full = preg_match('#^https://([^/?\#]+)([/?][^\#]*)?#i', $raw, $match) === 1 ? $match : null;

            if ($full === null || strtolower($full[1]) !== strtolower($host)) {
                return $wrong('Invalid URL. Use https:// and an address on '.$host.', or a path like /?sort=top.');
            }

            $raw = ($full[2] ?? '') === '' ? '/' : $full[2];
        } elseif (! str_starts_with($raw, '/') || str_starts_with($raw, '//')) {
            return $wrong('Invalid URL. Use a path that starts with /, such as /?sort=top, or a full https://'.$host.'/… address.');
        }

        $path = self::withoutTags($raw);

        if (preg_match('#^/(admin|livewire)(/|\?|$)#i', $path) === 1) {
            return $wrong('Point it at a page visitors see, not the admin.');
        }

        if (mb_strlen($path) > self::MAX_PATH) {
            return $wrong('Up to '.self::MAX_PATH.' characters.');
        }

        return ['path' => $path, 'error' => null];
    }

    /** Whether a source or a campaign is written the way a link keeps it. */
    public static function isToken(string $value): bool
    {
        return preg_match(self::TOKEN, $value) === 1;
    }

    /** A source as a link's name and list say it: the drawer's label, or the tag itself. */
    public static function sourceLabel(string $source): string
    {
        return self::SOURCES[$source] ?? $source;
    }

    /**
     * The name a link takes until the administrator names it: its source,
     * campaign and content, those there are, as the reference joins them.
     */
    public static function autoName(string $source, string $campaign, string $content): string
    {
        $parts = array_values(array_filter([$source === '' ? '' : self::sourceLabel($source), trim($campaign), trim($content)], fn (string $part): bool => $part !== ''));

        return mb_substr(implode(' · ', $parts), 0, self::MAX_NAME);
    }

    /** @return array{0: string, 1: string} the path before ? and the query after it, without the part after # */
    private static function split(string $path): array
    {
        $path = explode('#', $path, 2)[0];
        $parts = explode('?', $path, 2);
        $route = $parts[0] === '' ? '/' : $parts[0];

        return [$route, $parts[1] ?? ''];
    }

    /**
     * The parameters of a query that aren't campaign tags, as written. A
     * name counts as a utm_ one when PHP's request parser would read it as
     * one: decoded, + a space, leading spaces gone, and a dot, space or [ an
     * underscore — so `utm.content`, `utm+term` and `utm%2Esource` go too.
     *
     * @return list<string>
     */
    private static function kept(string $query): array
    {
        return array_values(array_filter(explode('&', $query), function (string $pair): bool {
            $name = strtr(ltrim(urldecode(explode('=', $pair, 2)[0]), ' '), ['.' => '_', ' ' => '_', '[' => '_']);

            return $pair !== '' && preg_match('/^utm_/i', $name) !== 1;
        }));
    }
}
