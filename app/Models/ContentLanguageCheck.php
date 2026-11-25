<?php

namespace App\Models;

use App\Enums\ContentLanguageCheckPriority;
use App\Enums\ContentLanguageCheckStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The check of one text's language — a post's title and description, or a comment's body: the local detector's
 * answer from when it was written, and OpenAI's once languages:work asked. Goes with its post or comment.
 *
 * @property int $id
 * @property int|null $post_id
 * @property int|null $comment_id
 * @property string|null $local_language
 * @property float|null $local_weight
 * @property string|null $ai_language
 * @property float|null $ai_confidence
 * @property ContentLanguageCheckStatus $status
 * @property ContentLanguageCheckPriority $priority
 * @property int $attempts
 * @property CarbonImmutable $run_after
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $finished_at
 */
class ContentLanguageCheck extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'post_id' => 'integer',
            'comment_id' => 'integer',
            'local_weight' => 'float',
            'ai_confidence' => 'float',
            'status' => ContentLanguageCheckStatus::class,
            'priority' => ContentLanguageCheckPriority::class,
            'attempts' => 'integer',
            'run_after' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * The language the check settles on: OpenAI's when it answered, otherwise the local detector's.
     */
    public function language(): ?string
    {
        return $this->ai_language ?? ($this->status === ContentLanguageCheckStatus::Done ? null : $this->local_language);
    }

    /**
     * How much the answer counts, 0 to 1: a language OpenAI named counts in full, the local detector's answer by
     * its weight (less for a text of only a few letters).
     */
    public function weight(): float
    {
        if ($this->ai_language !== null) {
            return 1.0;
        }

        return $this->status === ContentLanguageCheckStatus::Done ? 0.0 : (float) ($this->local_weight ?? 0.0);
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
