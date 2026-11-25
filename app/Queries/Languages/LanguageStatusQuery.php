<?php

namespace App\Queries\Languages;

use App\Enums\ContentLanguageCheckStatus;
use App\Models\ContentLanguageCheck;
use App\Models\Post;

/**
 * How the languages of posts and comments look on an installation (docs/architecture/language-ranking.md): the
 * published posts by language, where the checks of texts stand, how often the server's own reading agreed with
 * OpenAI's, and how often a post's text is in its author's language — what Project settings → Language ranking shows
 * and languages:report prints.
 */
final class LanguageStatusQuery
{
    /**
     * The published posts by language, most first — `none` for those no language holds half of.
     *
     * @return array<string, int>
     */
    public function postsByLanguage(): array
    {
        return Post::query()->published()->pluck('language')
            ->map(fn (?string $language): string => $language ?? 'none')
            ->countBy()
            ->sortKeys()
            ->sortDesc()
            ->all();
    }

    /**
     * How many checks stand at each status, in the order a check goes through them.
     *
     * @return array<string, int>
     */
    public function checks(): array
    {
        $counts = [];

        foreach (ContentLanguageCheckStatus::cases() as $status) {
            $counts[$status->value] = ContentLanguageCheck::query()->where('status', $status)->count();
        }

        return $counts;
    }

    /**
     * Of the texts both the server and OpenAI named a language for, how many they named the same.
     *
     * @return array{agreed: int, compared: int}
     */
    public function readingsAgreed(): array
    {
        $compared = fn () => ContentLanguageCheck::query()
            ->where('status', ContentLanguageCheckStatus::Done)
            ->whereNotNull('ai_language')
            ->whereNotNull('local_language');

        return ['agreed' => $compared()->whereColumn('local_language', 'ai_language')->count(), 'compared' => $compared()->count()];
    }

    /**
     * Of the posts OpenAI named a language for, by an author with a language, how many are in their author's.
     *
     * @return array{agreed: int, compared: int}
     */
    public function textsInTheirAuthorsLanguage(): array
    {
        $compared = fn () => ContentLanguageCheck::query()
            ->join('posts', 'posts.id', '=', 'content_language_checks.post_id')
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->where('content_language_checks.status', ContentLanguageCheckStatus::Done)
            ->whereNotNull('content_language_checks.ai_language')
            ->whereNotNull('users.locale');

        return ['agreed' => $compared()->whereColumn('content_language_checks.ai_language', 'users.locale')->count(), 'compared' => $compared()->count()];
    }
}
