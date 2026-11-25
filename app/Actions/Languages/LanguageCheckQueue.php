<?php

namespace App\Actions\Languages;

use App\Enums\ContentLanguageCheckPriority;
use App\Enums\ContentLanguageCheckStatus;
use App\Models\Comment;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Services\Languages\LocalLanguageDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The checks of texts' languages as languages:work takes them up: claimed under a lock, asked of OpenAI outside any
 * transaction, and each answer written back only if its claim still stands.
 *
 * A first attempt goes in a batch; one that failed is retried alone, so that a text OpenAI will not read — or one
 * that fails it otherwise — no longer takes a batch down with it. Three attempts, then the local detector's answer
 * stands for good.
 *
 * It also takes up the texts written before languages were kept, a few hundred at a time, so an installation fills
 * them in on its own after the release that brings them.
 */
final class LanguageCheckQueue
{
    public const ATTEMPTS = 3;

    /** A claim not written back within this many minutes is taken up again. */
    private const CLAIM_MINUTES = 10;

    /** The pause before each retry, by the attempt that failed. */
    private const RETRY_MINUTES = [1 => 1, 2 => 5];

    public function __construct(
        private readonly LocalLanguageDetector $detector,
        private readonly QueueLanguageCheckAction $checks,
    ) {}

    /**
     * Texts written before languages were kept, up to $limit — posts first, the newest first: each read on this
     * server and queued for OpenAI behind the texts written now, a comment given the language read, and the post
     * marked for its language to be worked out. Returns how many it read; 0 once every text has its check.
     */
    public function readOlderTexts(int $limit): int
    {
        $read = 0;

        $posts = Post::withTrashed()
            ->whereNotIn('id', ContentLanguageCheck::query()->whereNotNull('post_id')->select('post_id'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'title', 'description']);

        foreach ($posts as $post) {
            $text = $this->detector->read(trim($post->title."\n".($post->description ?? '')));
            DB::transaction(fn () => $this->checks->forPost($post, $text, ContentLanguageCheckPriority::Backfill));
            $this->markPostStaleById((int) $post->id);
            $read++;
        }

        $comments = Comment::withTrashed()
            ->whereNotIn('id', ContentLanguageCheck::query()->whereNotNull('comment_id')->select('comment_id'))
            ->orderByDesc('id')
            ->limit(max(0, $limit - $read))
            ->get(['id', 'post_id', 'body']);

        foreach ($comments as $comment) {
            $text = $this->detector->read((string) $comment->body);
            DB::transaction(function () use ($comment, $text): void {
                Comment::withoutTimestamps(fn () => Comment::withTrashed()->whereKey($comment->id)->update(['language' => $text->language()]));
                $this->checks->forComment($comment, $text, ContentLanguageCheckPriority::Backfill);
            });
            $this->markPostStaleById((int) $comment->post_id);
            $read++;
        }

        return $read;
    }

    /** Claims a run left behind, put back in the queue. */
    public function reclaimAbandoned(): void
    {
        ContentLanguageCheck::query()
            ->where('status', ContentLanguageCheckStatus::Sending)
            ->where('claimed_at', '<', CarbonImmutable::now()->subMinutes(self::CLAIM_MINUTES))
            ->update(['status' => ContentLanguageCheckStatus::Pending]);
    }

    /** How many checks were sent to OpenAI today (UTC), against the daily limit. */
    public function sentToday(): int
    {
        return ContentLanguageCheck::query()->where('claimed_at', '>=', CarbonImmutable::now('UTC')->startOfDay())->count();
    }

    /**
     * The next checks to ask OpenAI about, claimed: up to $limit first attempts — texts written now before a
     * backfill's, the newest first — or, when none wait, one retry.
     *
     * @return Collection<int, ContentLanguageCheck>
     */
    public function claim(int $limit): Collection
    {
        return DB::transaction(function () use ($limit): Collection {
            $now = CarbonImmutable::now();
            $due = fn () => ContentLanguageCheck::query()
                ->where('status', ContentLanguageCheckStatus::Pending)
                ->where('run_after', '<=', $now);

            $checks = $due()->where('attempts', 0)->where('priority', ContentLanguageCheckPriority::Live)
                ->orderByDesc('id')->limit($limit)->lockForUpdate()->get();

            if ($checks->count() < $limit) {
                $checks = $checks->concat($due()->where('attempts', 0)->where('priority', ContentLanguageCheckPriority::Backfill)
                    ->orderByDesc('id')->limit($limit - $checks->count())->lockForUpdate()->get());
            }

            if ($checks->isEmpty()) {
                $checks = $due()->where('attempts', '>', 0)->orderBy('run_after')->orderBy('id')->limit(1)->lockForUpdate()->get();
            }

            foreach ($checks as $check) {
                $check->forceFill(['status' => ContentLanguageCheckStatus::Sending, 'claimed_at' => $now, 'attempts' => $check->attempts + 1])->save();
            }

            return $checks->values();
        });
    }

    /**
     * The text each claimed check reads: a post's title and description, a comment's body — null for one gone.
     *
     * @param  Collection<int, ContentLanguageCheck>  $checks
     * @return array<int, string|null> by check id
     */
    public function texts(Collection $checks): array
    {
        $posts = Post::withTrashed()->whereIn('id', $checks->pluck('post_id')->filter()->all())->get(['id', 'title', 'description'])->keyBy('id');
        $comments = Comment::withTrashed()->whereIn('id', $checks->pluck('comment_id')->filter()->all())->get(['id', 'body'])->keyBy('id');
        $texts = [];

        foreach ($checks as $check) {
            $post = $check->post_id !== null ? $posts->get($check->post_id) : null;
            $comment = $check->comment_id !== null ? $comments->get($check->comment_id) : null;
            $texts[$check->id] = $post !== null ? trim($post->title."\n".($post->description ?? '')) : $comment?->body;
        }

        return $texts;
    }

    /**
     * OpenAI's answer, written back if the claim still stands: on the check, and on the comment it read. Locks the
     * comment before its check — the order every comment writer keeps — and never the post; the post's language
     * is marked to be worked out again in a statement of its own, once this commits.
     */
    public function settle(ContentLanguageCheck $claimed, ?string $language, float $confidence): void
    {
        $settled = DB::transaction(function () use ($claimed, $language, $confidence): bool {
            if ($claimed->comment_id !== null) {
                Comment::withTrashed()->whereKey($claimed->comment_id)->lockForUpdate()->first();
            }

            $check = ContentLanguageCheck::query()
                ->whereKey($claimed->id)
                ->where('status', ContentLanguageCheckStatus::Sending)
                ->where('attempts', $claimed->attempts)
                ->lockForUpdate()
                ->first();

            if ($check === null) {
                return false;
            }

            $check->forceFill([
                'ai_language' => $language,
                'ai_confidence' => $language === null ? null : round($confidence, 3),
                'status' => ContentLanguageCheckStatus::Done,
                'finished_at' => CarbonImmutable::now(),
            ])->save();

            if ($check->comment_id !== null) {
                Comment::withoutTimestamps(fn () => Comment::withTrashed()->whereKey($check->comment_id)->update(['language' => $language]));
            }

            return true;
        });

        if ($settled) {
            $this->markPostStale($claimed);
        }
    }

    /**
     * Checks OpenAI gave no answer for, put back to wait — or, after their last attempt, left on the local
     * detector's answer.
     *
     * @param  Collection<int, ContentLanguageCheck>  $claimed
     */
    public function release(Collection $claimed): void
    {
        foreach ($claimed as $claim) {
            $final = $claim->attempts >= self::ATTEMPTS;
            $now = CarbonImmutable::now();

            ContentLanguageCheck::query()
                ->whereKey($claim->id)
                ->where('status', ContentLanguageCheckStatus::Sending)
                ->where('attempts', $claim->attempts)
                ->update($final
                    ? ['status' => ContentLanguageCheckStatus::Failed, 'finished_at' => $now]
                    : ['status' => ContentLanguageCheckStatus::Pending, 'run_after' => $now->addMinutes(self::RETRY_MINUTES[$claim->attempts] ?? 30)]);
        }
    }

    private function markPostStale(ContentLanguageCheck $check): void
    {
        $postId = $check->post_id ?? Comment::withTrashed()->whereKey($check->comment_id)->value('post_id');

        if ($postId !== null) {
            $this->markPostStaleById((int) $postId);
        }
    }

    /** In a statement of its own: never a second update of the post in someone's transaction. */
    private function markPostStaleById(int $postId): void
    {
        Post::withoutTimestamps(fn () => Post::withTrashed()->whereKey($postId)->update(['language_stale_at' => CarbonImmutable::now()]));
    }
}
