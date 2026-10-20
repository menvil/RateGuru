<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StudioDiscussionNodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One comment of the discussion a Draft prepares for its post
 * (docs/architecture/studio-plans.md, "The discussion"): a comment on the
 * post, or a reply to one — two levels, never a reply to a reply — the
 * Persona who is to write it, its text, its place among its siblings, and how
 * long after the post it comes. It is a draft only: no comment exists for it,
 * and saving one publishes nothing.
 *
 * Its id is kept through every edit, so a later step can name one comment —
 * to write it again, or to publish it — without looking for it by its text.
 *
 * Written only by SaveStudioDiscussionTreeAction, under the plan's lock and
 * version. Nothing is mass assignable: every column is the server's to write.
 *
 * @property int $id
 * @property int $studio_plan_id
 * @property int|null $parent_id
 * @property int $persona_user_id
 * @property string $body
 * @property int $sort_order
 * @property int $offset_seconds
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class StudioDiscussionNode extends Model
{
    /** @use HasFactory<StudioDiscussionNodeFactory> */
    use HasFactory;

    protected $table = 'studio_plan_discussion_nodes';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'studio_plan_id' => 'integer',
            'parent_id' => 'integer',
            'persona_user_id' => 'integer',
            'sort_order' => 'integer',
            'offset_seconds' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<StudioPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudioPlan::class, 'studio_plan_id');
    }

    /**
     * The comment a reply answers — always a comment on the post.
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * A comment's replies, in their order. A reply has none.
     *
     * @return HasMany<self, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The Persona who is to write it. It keeps its Persona through a sanction
     * or a deletion of the account; whether that Persona can still write it is
     * asked before it is published.
     *
     * @return BelongsTo<User, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(User::class, 'persona_user_id');
    }

    /** Whether it is a comment on the post rather than a reply. */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }
}
