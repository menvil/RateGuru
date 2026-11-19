<?php

namespace App\Models;

use App\Enums\GameAudience;
use App\Support\Games\Traffic\TrafficScope;
use Carbon\CarbonImmutable;
use Database\Factories\GameTrafficPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stretch of time one allocation of an audience's visitors was in effect,
 * open until the next allocation is applied. Its versions carry their share
 * on the pivot; a period without any is an explicit "no traffic". An ended
 * period can be hidden from Growth → Experiments, its numbers kept.
 *
 * A period is everyone's — the game's general split — or a split for the paid
 * clicks of one traffic source, or of one of its campaigns (TrafficScope).
 * `open_scope` names that scope while the period is open, so a game has one
 * open period per audience and scope.
 *
 * @property int $id
 * @property int $game_id
 * @property GameAudience $audience
 * @property int|null $source_id
 * @property int|null $campaign_id
 * @property string|null $open_scope
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $hidden_at
 * @property int $started_by_user_id
 */
class GameTrafficPeriod extends Model
{
    /** @use HasFactory<GameTrafficPeriodFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'audience' => GameAudience::class,
            'source_id' => 'integer',
            'campaign_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'hidden_at' => 'immutable_datetime',
            'started_by_user_id' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /** Whom the period is for: everyone, or the paid clicks of a source or one of its campaigns. */
    public function scope(): TrafficScope
    {
        return TrafficScope::of($this->source_id, $this->campaign_id);
    }

    /**
     * The scope in words, for a split for a source — “ExoClick tests”, “PopAds
     * main · Campaign 881377” — with its source and campaign loaded; null for
     * everyone's period.
     */
    public function scopeLabel(): ?string
    {
        if ($this->source_id === null) {
            return null;
        }

        /** @var TrafficSource|null $source */
        $source = $this->getRelationValue('source');
        /** @var TrafficCampaign|null $campaign */
        $campaign = $this->campaign_id === null ? null : $this->getRelationValue('campaign');

        return implode(' · ', array_filter([$source->name ?? 'Source '.$this->source_id, $campaign?->label()]));
    }

    /**
     * The versions sharing the period, each with its `share_percent`, in
     * version order.
     *
     * @return BelongsToMany<GameVersion, $this>
     */
    public function versions(): BelongsToMany
    {
        return $this->belongsToMany(GameVersion::class, 'game_traffic_shares', 'period_id', 'version_id')
            ->withPivot('share_percent')
            ->orderBy('game_versions.number');
    }

    /**
     * The shares, version id to percentage, in version order.
     *
     * @return array<int, int>
     */
    public function shares(): array
    {
        $shares = [];

        foreach ($this->versions as $version) {
            /** @var object{share_percent: int|string} $pivot */
            $pivot = $version->getRelation('pivot');
            $shares[(int) $version->id] = (int) $pivot->share_percent;
        }

        return $shares;
    }

    /** @return HasMany<GameAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(GameAssignment::class, 'period_id');
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }

    /** @return BelongsTo<TrafficCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(TrafficCampaign::class, 'campaign_id');
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
