<?php

namespace App\Models;

use App\Enums\GameVersionStatus;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use Carbon\CarbonImmutable;
use Database\Factories\GameVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A published, playable game, frozen whole when it was published
 * (PublishGameVersionAction): its snapshot is what visitors play, and it
 * never changes. Only whether the version may still take traffic — its
 * status — does.
 *
 * @property int $id
 * @property int $game_id
 * @property int $number
 * @property GameVersionStatus $status
 * @property int $content_set_id
 * @property int $flow_id
 * @property array<string, mixed> $snapshot
 * @property int $snapshot_schema
 * @property int $round_count
 * @property int $scored_round_count
 * @property int $max_score
 * @property int $published_by_user_id
 * @property CarbonImmutable $published_at
 * @property CarbonImmutable|null $retired_at
 */
class GameVersion extends Model
{
    /** @use HasFactory<GameVersionFactory> */
    use HasFactory;

    /** The only columns a published version may still change. */
    private const MUTABLE = ['status', 'retired_at', 'updated_at'];

    /** @var list<string> */
    protected $guarded = ['id'];

    private ?GameVersionSnapshot $readSnapshot = null;

    protected static function booted(): void
    {
        static::updating(function (GameVersion $version): void {
            $changed = array_diff(array_keys($version->getDirty()), self::MUTABLE);

            if ($changed !== []) {
                throw new LogicException('A published game version is immutable; only its status changes.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'number' => 'integer',
            'status' => GameVersionStatus::class,
            'content_set_id' => 'integer',
            'flow_id' => 'integer',
            'snapshot' => 'array',
            'snapshot_schema' => 'integer',
            'round_count' => 'integer',
            'scored_round_count' => 'integer',
            'max_score' => 'integer',
            'published_by_user_id' => 'integer',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** What the version plays from, read once per model. */
    public function read(): GameVersionSnapshot
    {
        return $this->readSnapshot ??= GameVersionSnapshot::fromArray($this->snapshot);
    }

    public function label(): string
    {
        return 'V'.$this->number;
    }

    public function isActive(): bool
    {
        return $this->status === GameVersionStatus::Active;
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<GameContentSet, $this> */
    public function contentSet(): BelongsTo
    {
        return $this->belongsTo(GameContentSet::class, 'content_set_id');
    }

    /** @return BelongsTo<GameFlow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(GameFlow::class, 'flow_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /** @return HasMany<GameAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(GameAttempt::class, 'version_id');
    }

    /**
     * The uploaded pictures the version keeps alive.
     *
     * @return BelongsToMany<MediaAsset, $this>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'game_version_assets', 'version_id', 'media_asset_id');
    }

    /**
     * The posts the version shows. History only: a post leaving the site
     * leaves its id here.
     *
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'game_version_posts', 'version_id', 'post_id');
    }
}
