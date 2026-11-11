<?php

namespace App\Models;

use App\Enums\GameMechanic;
use App\Enums\GameStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A game as an administrator edits it in Growth → Games: its identity, its
 * cover, its content sets and flows. Visitors never play this row's content;
 * they play a published version's snapshot (GameVersion), so nothing edited
 * here reaches them until a new version is published.
 *
 * Written only by the game actions (app/Actions/Games). The slug and the
 * mechanic stop changing once a version has been published
 * (`first_published_at`): shared links point at the slug.
 *
 * @property int $id
 * @property string $slug
 * @property string $internal_name
 * @property string $title
 * @property array<string, string>|null $title_translations
 * @property string|null $description
 * @property array<string, string>|null $description_translations
 * @property GameMechanic $mechanic
 * @property GameStatus $status
 * @property int|null $cover_asset_id
 * @property int $last_version_number
 * @property CarbonImmutable|null $first_published_at
 * @property CarbonImmutable|null $status_changed_at
 * @property int $created_by_user_id
 * @property int $updated_by_user_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['slug', 'internal_name', 'title', 'description', 'mechanic'])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'mechanic' => 'versus',
        'last_version_number' => 0,
        'lock_version' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mechanic' => GameMechanic::class,
            'status' => GameStatus::class,
            'title_translations' => 'array',
            'description_translations' => 'array',
            'cover_asset_id' => 'integer',
            'last_version_number' => 'integer',
            'first_published_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Whether a version has ever been published, which locks the slug and the mechanic. */
    public function hasBeenPublished(): bool
    {
        return $this->first_published_at !== null;
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_asset_id');
    }

    /** @return HasMany<GameContentSet, $this> */
    public function contentSets(): HasMany
    {
        return $this->hasMany(GameContentSet::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<GameFlow, $this> */
    public function flows(): HasMany
    {
        return $this->hasMany(GameFlow::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<GameVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(GameVersion::class)->orderByDesc('number');
    }

    /** @return HasMany<GameTrafficPeriod, $this> */
    public function trafficPeriods(): HasMany
    {
        return $this->hasMany(GameTrafficPeriod::class);
    }

    /** @return HasMany<GameAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(GameAttempt::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
