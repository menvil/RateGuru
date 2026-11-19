<?php

namespace App\Models;

use App\Enums\GameAudience;
use App\Enums\GameFlowGoal;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnwardKind;
use App\Enums\GameVersionStatus;
use App\Support\Games\Flow\FlowGoalRules;
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
 * @property GameFlowGoal|null $goal
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
            'goal' => GameFlowGoal::class,
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

    /** The goal the version counts: the one it was published with. */
    public function flowGoal(): GameFlowGoal
    {
        return $this->goal ?? $this->read()->flowGoal ?? FlowGoalRules::defaultFor($this->read()->flowSteps());
    }

    public function label(): string
    {
        return 'V'.$this->number;
    }

    /** Whether an anonymous visitor of the version can meet an Email capture step. */
    public function asksAnonymousForEmail(): bool
    {
        foreach ($this->read()->steps as $step) {
            if ($step->type === GameFlowStepType::EmailCapture && $step->audience->includes(GameAudience::Anonymous)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the version's flow can send a visitor of the audience on from the
     * game, in the order of GameOnwardKind: Full Result going on to a post,
     * the feed or a page, or the exit step that ends the flow — when it is
     * shown to them.
     *
     * @return list<GameOnwardKind>
     */
    public function waysOn(GameAudience $audience): array
    {
        $ways = [];

        foreach ($this->read()->flowSteps() as $step) {
            if (! $step->audience->includes($audience)) {
                continue;
            }

            $ways[] = match (true) {
                $step->sendsToPost() => GameOnwardKind::Post,
                $step->sendsToFeed() => GameOnwardKind::Feed,
                $step->sendsToPage() => GameOnwardKind::Page,
                $step->type === GameFlowStepType::NextGame => GameOnwardKind::NextGame,
                default => null,
            };
        }

        return array_values(array_filter(GameOnwardKind::cases(), fn (GameOnwardKind $way): bool => in_array($way, $ways, true)));
    }

    /** Whether a step that sends a visitor of the audience to a post asks them to answer rating groups there. */
    public function asksForPostRatings(GameAudience $audience): bool
    {
        foreach ($this->read()->flowSteps() as $step) {
            if ($step->audience->includes($audience) && $step->sendsToPost() && $step->askGroups() !== []) {
                return true;
            }
        }

        return false;
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
