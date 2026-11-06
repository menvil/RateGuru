<?php

namespace App\Models;

use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use Carbon\CarbonImmutable;
use Database\Factories\StudioPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One publication prepared in Content Studio: the photo, the post's texts and
 * taxonomy, the Persona it is to be published as, and when. A plan is not a
 * post — nothing of it is on the site until it is approved and run, and until
 * then no post exists for it at all.
 *
 * Approval (ApproveStudioPlanAction) records who approved it and when, and
 * writes the action that will publish it (`actions`); an approved plan is no
 * longer edited or deleted.
 *
 * A Draft may be as incomplete as an editor leaves it: no author, no photo,
 * no title, no category, no date. Whatever it does hold has passed the checks
 * a post's own fields pass (StudioPlanValidationRules); whether it is complete
 * enough to publish is asked when it is approved.
 *
 * Written only by the Content Studio actions (app/Actions/Studio). Only the
 * fields an editor sets are fillable; the status, the creator, the photo, the
 * approval and the lock version are the server's to write.
 *
 * @property int $id
 * @property StudioPlanStatus $status
 * @property int|null $author_user_id
 * @property int $created_by_user_id
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable|null $approved_at
 * @property int|null $image_asset_id
 * @property string|null $title
 * @property string|null $description
 * @property string|null $source_url
 * @property int|null $category_id
 * @property list<int> $tag_ids
 * @property list<int> $author_answer_option_ids
 * @property StudioPlanPublicationMode $publication_mode
 * @property CarbonImmutable|null $publish_at
 * @property int $lock_version
 */
#[Fillable(['author_user_id', 'title', 'description', 'source_url', 'category_id', 'tag_ids', 'author_answer_option_ids', 'publication_mode', 'publish_at'])]
class StudioPlan extends Model
{
    /** @use HasFactory<StudioPlanFactory> */
    use HasFactory;

    /**
     * A new plan is an empty Draft, published as soon as it runs, at its
     * first version.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'tag_ids' => '[]',
        'author_answer_option_ids' => '[]',
        'publication_mode' => 'now',
        'lock_version' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StudioPlanStatus::class,
            'author_user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'approved_at' => 'immutable_datetime',
            'image_asset_id' => 'integer',
            'category_id' => 'integer',
            'tag_ids' => 'array',
            'author_answer_option_ids' => 'array',
            'publication_mode' => StudioPlanPublicationMode::class,
            'publish_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * The Persona the post is to be published as. A plan keeps its author
     * through a sanction or a deletion of the account; whether that author can
     * still publish is asked when the plan is approved.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The administrator who approved it, once it is approved.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * The photo: a post image owned by the administrator who uploaded it,
     * never by the author, so changing the author leaves it where it is.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function imageAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_asset_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Its photo analysis — about the photo it has now, or about one it no
     * longer has, which is no analysis of the current photo at all
     * (StudioPlanImageAnalysis::isFor()).
     *
     * @return HasOne<StudioPlanImageAnalysis, $this>
     */
    public function imageAnalysis(): HasOne
    {
        return $this->hasOne(StudioPlanImageAnalysis::class);
    }

    /**
     * Every batch of post text suggestions it asked for — those written from
     * its photo, analysis and author's voice as they are now, and those from
     * earlier ones, kept as history (StudioPlanTextGeneration::isFor()).
     *
     * @return HasMany<StudioPlanTextGeneration, $this>
     */
    public function textGenerations(): HasMany
    {
        return $this->hasMany(StudioPlanTextGeneration::class);
    }

    /**
     * The scheduled writes approval made for it — today, the one that
     * publishes its post. Deleted with the plan.
     *
     * @return HasMany<StudioAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(StudioAction::class);
    }

    /**
     * The discussion it prepares for its post — comments on it and replies to
     * them — in the order they are listed: each level by its place, then by
     * id. Drafts only, until it is approved; deleted with the plan.
     *
     * @return HasMany<StudioDiscussionNode, $this>
     */
    public function discussionNodes(): HasMany
    {
        return $this->hasMany(StudioDiscussionNode::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The votes it prepares for its post, its comments and its rating
     * questions, in the order they are cast: by their moment, then their
     * place, then id. Intentions only, until it is approved; deleted with the
     * plan.
     *
     * @return HasMany<StudioPlanVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(StudioPlanVote::class)->orderBy('offset_seconds')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Every discussion generation it asked for — proposals written from its
     * post, photo, discussion and Personas as they were then, kept as history
     * whether or not one was taken into its discussion. Deleted with the plan.
     *
     * @return HasMany<StudioPlanDiscussionGeneration, $this>
     */
    public function discussionGenerations(): HasMany
    {
        return $this->hasMany(StudioPlanDiscussionGeneration::class);
    }

    public function isDraft(): bool
    {
        return $this->status === StudioPlanStatus::Draft;
    }
}
