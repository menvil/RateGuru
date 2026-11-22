<?php

namespace App\Support\Analytics;

use App\Enums\TrafficKnownReason;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Money\TrafficMoney;

/**
 * One visit as Growth → Analytics reads it (analytics_visits), worked out
 * from its row of traffic_visits: the browser as a number — the first 64
 * bits of its hash, a signed integer — each step a flag, the sums, the
 * columns a split or a filter names, what it did with posts
 * (POST_FIGURES, from its rows of traffic_visit_posts), what was played on
 * it (ATTEMPT_FIGURES, from the attempts started on it) and, credited with
 * an account, the post its session opened first (sessionPost()). Every
 * value comes out as the text the database hands back once stored, whatever
 * the engine, so a row written before can be told apart from the one the
 * visit makes now by comparing them (same()). Pure.
 */
final class AnalyticsVisitRow
{
    /** @var list<string> the columns of traffic_visits a row is made from */
    public const array SOURCE_COLUMNS = [
        'id', 'network_day', 'arrived_at', 'last_seen_at', 'entry_visit_id', 'channel', 'source_id', 'campaign_id', 'visitor_hash', 'opened_at',
        'known', 'known_reason', 'started_at', 'completed_at', 'goal_at', 'signup_form_at', 'signup_at', 'account_at', 'activated_at',
        'post_published_at', 'returned_at', 'signup_after_seconds', 'account_after_seconds', 'votes', 'comments', 'posts_created',
        'spend', 'spend_estimated', 'value_real', 'value_expected', 'bot_flags', 'source_key', 'campaign_key', 'creative_key',
        'landing_key', 'device', 'country', 'city', 'game_id', 'version_id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        'utm_term',
    ];

    /** @var list<string> the columns of a row, in order */
    public const array COLUMNS = [
        'id', 'network_day', 'arrived_at', 'last_seen_at', 'entry_visit_id', 'channel', 'source_id', 'campaign_id', 'visitor_key', 'visitor_hash', 'opened', 'known',
        'signed_in', 'started', 'completed', 'goal', 'signup_form', 'signed_up', 'account', 'activated', 'post_published', 'returned',
        'signup_at', 'signup_after_seconds', 'account_after_seconds', 'votes', 'comments', 'posts_created', 'spend', 'spend_estimated',
        'value_real', 'value_expected', 'tagged', 'crawler', 'source_key', 'campaign_key', 'creative_key', 'landing_key', 'device',
        'country', 'city', 'game_id', 'version_id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'post_rows', 'post_views', 'post_viewed', 'post_voted', 'post_shares',
        'attempts', 'share_clicks', 'shared_results', 'session_post_id',
    ];

    /** @var list<string> what a visit did with posts, worked out from its rows of traffic_visit_posts (postFigures()) */
    public const array POST_FIGURES = ['post_rows', 'post_views', 'post_viewed', 'post_voted', 'post_shares'];

    /** @var list<string> what was played on a visit, worked out from the attempts started on it (attemptFigures()) */
    public const array ATTEMPT_FIGURES = ['attempts', 'share_clicks', 'shared_results'];

    private const array FLAGS = ['opened', 'known', 'signed_in', 'started', 'completed', 'goal', 'signup_form', 'signed_up', 'account', 'activated', 'post_published', 'returned', 'spend_estimated', 'tagged', 'crawler', 'post_viewed', 'post_voted'];

    private const array NUMBERS = ['id', 'entry_visit_id', 'source_id', 'campaign_id', 'visitor_key', 'signup_after_seconds', 'account_after_seconds', 'votes', 'comments', 'posts_created', 'game_id', 'version_id', 'post_rows', 'post_views', 'post_shares', 'attempts', 'share_clicks', 'shared_results', 'session_post_id'];

    private const array MONEY = ['spend', 'value_real', 'value_expected'];

    private const array MOMENTS = ['arrived_at', 'last_seen_at', 'signup_at'];

    /**
     * What a visit did with posts, from its rows of traffic_visit_posts: how
     * many rows it has — one voted on from the feed, never opened, is one —
     * the times it opened one, whether it opened any, whether it voted on one
     * it opened, and how many it shared.
     *
     * @param  iterable<array{views: int|string, voted: bool|int|string, shared_at: mixed}>  $posts
     * @return array{post_rows: int, post_views: int, post_viewed: bool, post_voted: bool, post_shares: int}
     */
    public static function postFigures(iterable $posts): array
    {
        $figures = ['post_rows' => 0, 'post_views' => 0, 'post_viewed' => false, 'post_voted' => false, 'post_shares' => 0];

        foreach ($posts as $post) {
            $views = (int) $post['views'];
            $figures['post_rows']++;
            $figures['post_views'] += $views;
            $figures['post_viewed'] = $figures['post_viewed'] || $views > 0;
            $figures['post_voted'] = $figures['post_voted'] || ($views > 0 && self::flag($post['voted']));
            $figures['post_shares'] += $post['shared_at'] !== null ? 1 : 0;
        }

        return $figures;
    }

    /**
     * What was played on a visit, from the attempts started on it, each once
     * for every click on its result's share buttons and once more with none
     * (a share's id, or null): how many attempts, how many clicks, and how
     * many attempts had a click — an attempt is started on one visit at most,
     * so the visits' figures add up to their attempts'.
     *
     * @param  iterable<array{id: int|string, share_id: int|string|null}>  $attempts
     * @return array{attempts: int, share_clicks: int, shared_results: int}
     */
    public static function attemptFigures(iterable $attempts): array
    {
        $played = [];
        $shared = [];
        $clicks = 0;

        foreach ($attempts as $attempt) {
            $played[(int) $attempt['id']] = true;

            if ($attempt['share_id'] !== null) {
                $shared[(int) $attempt['id']] = true;
                $clicks++;
            }
        }

        return ['attempts' => count($played), 'share_clicks' => $clicks, 'shared_results' => count($shared)];
    }

    /**
     * The post a session opened first, from the rows of the posts its visits
     * opened (views > 0), each with the moment it was first opened: the
     * earliest, the lower id at the same moment; none when it opened none.
     *
     * @param  iterable<array{post_id: int|string, first_at: mixed}>  $opened
     */
    public static function sessionPost(iterable $opened): ?int
    {
        $first = null;

        foreach ($opened as $row) {
            $at = substr(str_replace('T', ' ', (string) $row['first_at']), 0, 19);
            $postId = (int) $row['post_id'];
            $order = $first === null ? -1 : strcmp($at, $first[0]);

            if ($order < 0 || ($order === 0 && $postId < $first[1])) {
                $first = [$at, $postId];
            }
        }

        return $first === null ? null : $first[1];
    }

    /**
     * The row a visit makes, from its columns (SOURCE_COLUMNS) as the
     * database hands them back, what it did with posts (postFigures()), what
     * was played on it (attemptFigures()) and its session's first post
     * (sessionPost()) — none when they aren't given.
     *
     * @param  array<string, mixed>  $visit
     * @return array<string, string|null>
     */
    public static function fromVisit(array $visit): array
    {
        $set = fn (string $column): bool => ($visit[$column] ?? null) !== null;

        return self::normalized([
            'id' => $visit['id'],
            'network_day' => $visit['network_day'],
            'arrived_at' => $visit['arrived_at'],
            'last_seen_at' => $visit['last_seen_at'],
            'entry_visit_id' => $visit['entry_visit_id'] ?? null,
            'channel' => $visit['channel'],
            'source_id' => $visit['source_id'] ?? null,
            'campaign_id' => $visit['campaign_id'] ?? null,
            'visitor_key' => self::key((string) $visit['visitor_hash']),
            // Only a visit the tracker counts keeps its hash: a game's Analytics reads no other.
            'visitor_hash' => $set('game_id') || $set('source_id') ? $visit['visitor_hash'] : null,
            'opened' => $set('opened_at'),
            'known' => self::flag($visit['known'] ?? false),
            'signed_in' => ($visit['known_reason'] ?? null) === TrafficKnownReason::SignedIn->value,
            'started' => $set('started_at'),
            'completed' => $set('completed_at'),
            'goal' => $set('goal_at'),
            'signup_form' => $set('signup_form_at'),
            'signed_up' => $set('signup_at'),
            'account' => $set('account_at'),
            'activated' => $set('activated_at'),
            'post_published' => $set('post_published_at'),
            'returned' => $set('returned_at'),
            'signup_at' => $visit['signup_at'] ?? null,
            'signup_after_seconds' => $visit['signup_after_seconds'] ?? null,
            'account_after_seconds' => $visit['account_after_seconds'] ?? null,
            'votes' => $visit['votes'] ?? 0,
            'comments' => $visit['comments'] ?? 0,
            'posts_created' => $visit['posts_created'] ?? 0,
            'spend' => $visit['spend'] ?? 0,
            'spend_estimated' => self::flag($visit['spend_estimated'] ?? false),
            'value_real' => $visit['value_real'] ?? 0,
            'value_expected' => $visit['value_expected'] ?? 0,
            'tagged' => $set('utm_source') || $set('utm_medium'),
            'crawler' => ((int) ($visit['bot_flags'] ?? 0) & BotSignals::CRAWLER) !== 0,
            'source_key' => $visit['source_key'] ?? null,
            'campaign_key' => $visit['campaign_key'] ?? null,
            'creative_key' => $visit['creative_key'] ?? null,
            'landing_key' => $visit['landing_key'] ?? null,
            'device' => $visit['device'],
            'country' => $visit['country'] ?? null,
            'city' => $visit['city'] ?? null,
            'game_id' => $visit['game_id'] ?? null,
            'version_id' => $visit['version_id'] ?? null,
            'utm_source' => $visit['utm_source'] ?? null,
            'utm_medium' => $visit['utm_medium'] ?? null,
            'utm_campaign' => $visit['utm_campaign'] ?? null,
            'utm_content' => $visit['utm_content'] ?? null,
            'utm_term' => $visit['utm_term'] ?? null,
            'post_rows' => $visit['post_rows'] ?? 0,
            'post_views' => $visit['post_views'] ?? 0,
            'post_viewed' => self::flag($visit['post_viewed'] ?? false),
            'post_voted' => self::flag($visit['post_voted'] ?? false),
            'post_shares' => $visit['post_shares'] ?? 0,
            'attempts' => $visit['attempts'] ?? 0,
            'share_clicks' => $visit['share_clicks'] ?? 0,
            'shared_results' => $visit['shared_results'] ?? 0,
            'session_post_id' => $visit['session_post_id'] ?? null,
        ]);
    }

    /**
     * A row of analytics_visits as the database handed it back, in the same
     * text a visit's row comes out in.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, string|null>
     */
    public static function fromStored(array $stored): array
    {
        return self::normalized($stored);
    }

    /**
     * @param  array<string, string|null>  $one
     * @param  array<string, string|null>  $other
     */
    public static function same(array $one, array $other): bool
    {
        return $one === $other;
    }

    /**
     * The number a browser's hash is known by: its first 16 hex digits — 64
     * bits of a SHA-256 — read as a signed integer, the bigint a database
     * stores. A hash not written in hex is hashed again first.
     */
    public static function key(string $hash): int
    {
        $head = substr($hash, 0, 16);

        if (strlen($head) !== 16 || ! ctype_xdigit($head)) {
            $head = hash('xxh64', $hash);
        }

        /** @var array{1: int} $number */
        $number = unpack('J', (string) hex2bin($head));

        return $number[1];
    }

    /**
     * Every column of a row, in order, as text: a flag 1 or 0, a number its
     * digits, a sum of money to its stored scale, a moment to the second.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string|null>
     */
    private static function normalized(array $row): array
    {
        $normalized = [];

        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;

            $normalized[$column] = match (true) {
                in_array($column, self::FLAGS, true) => self::flag($value) ? '1' : '0',
                $value === null => null,
                in_array($column, self::NUMBERS, true) => (string) (int) $value,
                in_array($column, self::MONEY, true) => TrafficMoney::stored($value),
                in_array($column, self::MOMENTS, true) => substr(str_replace('T', ' ', (string) $value), 0, 19),
                default => (string) $value,
            };
        }

        return $normalized;
    }

    /** A flag as the engines hand one back: true or false, 1 or 0, "1", "0", "t" or "f". */
    private static function flag(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_string($value) => ! in_array(strtolower($value), ['', '0', 'f', 'false'], true),
            default => (bool) $value,
        };
    }
}
