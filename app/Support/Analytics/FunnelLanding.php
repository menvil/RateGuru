<?php

namespace App\Support\Analytics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the people a registration funnel counts landed: anywhere, the home
 * feed, any post, one post, or any other page — as the address keeps it
 * (key(), read back by fromKey()), and as a narrowing of Analytics' own rows
 * by their session's landing (`landing_key`, `landing_post_id`). Pure.
 */
final readonly class FunnelLanding
{
    public const string FEED = 'feed';

    public const string POST = 'post';

    public const string OTHER = 'other';

    private const string FEED_ROUTE = 'feed';

    private const string POST_ROUTE = 'posts.show';

    private function __construct(public ?string $kind, public ?int $postId = null) {}

    public static function any(): self
    {
        return new self(null);
    }

    public static function feed(): self
    {
        return new self(self::FEED);
    }

    public static function anyPost(): self
    {
        return new self(self::POST);
    }

    public static function post(int $postId): self
    {
        return new self(self::POST, $postId);
    }

    public static function other(): self
    {
        return new self(self::OTHER);
    }

    /** The landing an address names (key()); anywhere for one it doesn't know. */
    public static function fromKey(?string $key): self
    {
        return match (true) {
            $key === self::FEED => self::feed(),
            $key === self::POST => self::anyPost(),
            $key === self::OTHER => self::other(),
            is_string($key) && preg_match('/^post:([1-9][0-9]{0,17})$/', $key, $match) === 1 => self::post((int) $match[1]),
            default => self::any(),
        };
    }

    /** What the address keeps: empty for anywhere. */
    public function key(): string
    {
        return match (true) {
            $this->kind === null => '',
            $this->postId !== null => self::POST.':'.$this->postId,
            default => $this->kind,
        };
    }

    public function isAnywhere(): bool
    {
        return $this->kind === null;
    }

    /**
     * The rows of the sessions that landed here.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $rows
     * @return Builder<TModel>
     */
    public function narrow(Builder $rows): Builder
    {
        return match (true) {
            $this->kind === self::FEED => $rows->where('landing_key', self::FEED_ROUTE),
            $this->postId !== null => $rows->where('landing_post_id', $this->postId),
            $this->kind === self::POST => $rows->where('landing_key', self::POST_ROUTE),
            $this->kind === self::OTHER => $rows->where(fn (Builder $elsewhere) => $elsewhere->whereNull('landing_key')->orWhereNotIn('landing_key', [self::FEED_ROUTE, self::POST_ROUTE])),
            default => $rows,
        };
    }
}
