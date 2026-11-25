<?php

namespace App\Actions\Languages;

use App\Enums\ContentLanguageCheckPriority;
use App\Enums\ContentLanguageCheckStatus;
use App\Models\Comment;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Support\Languages\TextLanguage;
use Carbon\CarbonImmutable;

/**
 * The check of a new text's language: what the local detector said, waiting for OpenAI to read it again
 * (languages:work) — or settled at once for a text with no words to read.
 *
 * Inside the transaction that wrote the post or the comment, after it: a child row of the text, locked after it.
 */
final class QueueLanguageCheckAction
{
    public function forPost(Post $post, TextLanguage $read, ContentLanguageCheckPriority $priority = ContentLanguageCheckPriority::Live): ContentLanguageCheck
    {
        return $this->queue(['post_id' => $post->id], $read, $priority);
    }

    public function forComment(Comment $comment, TextLanguage $read, ContentLanguageCheckPriority $priority = ContentLanguageCheckPriority::Live): ContentLanguageCheck
    {
        return $this->queue(['comment_id' => $comment->id], $read, $priority);
    }

    /**
     * @param  array{post_id?: int, comment_id?: int}  $text
     */
    private function queue(array $text, TextLanguage $read, ContentLanguageCheckPriority $priority): ContentLanguageCheck
    {
        $now = CarbonImmutable::now();

        return ContentLanguageCheck::query()->create([
            ...$text,
            'local_language' => $read->language(),
            'local_weight' => $read->detected === null ? null : round($read->weight(), 3),
            'status' => $read->hasWords ? ContentLanguageCheckStatus::Pending : ContentLanguageCheckStatus::Skipped,
            'priority' => $priority,
            'run_after' => $now,
            'finished_at' => $read->hasWords ? null : $now,
        ]);
    }
}
