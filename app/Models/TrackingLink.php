<?php

namespace App\Models;

use App\Enums\TrackingLinkDestination;
use App\Support\Analytics\UtmTags;
use Carbon\CarbonImmutable;
use Database\Factories\TrackingLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An administrator's tagged address, made under Analytics → Tracking links:
 * where it leads — a game, one of the site's pages, or a path of their own,
 * kept as the path the address was built on — and the five campaign tags it
 * carries, the content and the term '' when there are none. The visits whose
 * session came by the same five tags are its own; no two links carry the
 * same five. Saved through SaveTrackingLinkAction under its lock_version;
 * once a visit came through it, only its name changes. Never deleted.
 *
 * @property int $id
 * @property string $name
 * @property TrackingLinkDestination $destination
 * @property int|null $game_id
 * @property string $path
 * @property string $utm_source
 * @property string $utm_medium
 * @property string $utm_campaign
 * @property string $utm_content
 * @property string $utm_term
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrackingLink extends Model
{
    /** @use HasFactory<TrackingLinkFactory> */
    use HasFactory;

    protected $table = 'tracking_links';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'destination' => TrackingLinkDestination::class,
            'game_id' => 'integer',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** How the link reads where it is named by number: TL-12. */
    public function reference(): string
    {
        return 'TL-'.$this->id;
    }

    /** The five tags as a visit of the link keeps them: an empty content or term is none. */
    public function tags(): UtmTags
    {
        return UtmTags::fromQuery([
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'utm_content' => $this->utm_content,
            'utm_term' => $this->utm_term,
        ]);
    }
}
