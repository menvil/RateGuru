<?php

namespace App\Console\Commands;

use App\Enums\ContentLanguageCheckStatus;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use Illuminate\Console\Command;

/**
 * What the languages of posts and comments look like on this installation (docs/architecture/language-ranking.md):
 * how many published posts each language has, where the checks of texts stand, how often the server's own reading
 * agreed with OpenAI's, and how often a post's text is in its author's language — the numbers the weights of a
 * post's language were chosen on, read on real posts.
 */
final class LanguagesReportCommand extends Command
{
    protected $signature = 'languages:report';

    protected $description = 'Report the languages of posts and how well they are told.';

    public function handle(): int
    {
        $posts = Post::query()->published()->pluck('language')->map(fn (?string $language): string => $language ?? 'none')->countBy()->sortKeys()->sortDesc();
        $total = max(1, $posts->sum());

        $this->info('Published posts by language');
        $this->table(['Language', 'Posts', 'Share'], $posts->map(fn (int $count, string $language): array => [$language, $count, $this->percent($count, $total)])->values()->all());

        $statuses = [];
        $compared = $agreed = 0;

        foreach (ContentLanguageCheck::query()->select(['id', 'status', 'local_language', 'ai_language'])->lazyById(1000) as $check) {
            $statuses[$check->status->value] = ($statuses[$check->status->value] ?? 0) + 1;

            if ($check->status === ContentLanguageCheckStatus::Done && $check->ai_language !== null && $check->local_language !== null) {
                $compared++;
                $agreed += (int) ($check->local_language === $check->ai_language);
            }
        }

        $this->info('Checks of texts');
        $this->table(['Status', 'Checks'], collect($statuses)->map(fn (int $count, string $status): array => [$status, $count])->values()->all());
        $this->line("The server's own reading agreed with OpenAI on {$agreed} of {$compared} texts both read (".$this->percent($agreed, max(1, $compared)).').');

        $authored = $sameAsAuthor = 0;

        foreach (ContentLanguageCheck::query()
            ->join('posts', 'posts.id', '=', 'content_language_checks.post_id')
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->where('content_language_checks.status', ContentLanguageCheckStatus::Done)
            ->whereNotNull('content_language_checks.ai_language')
            ->whereNotNull('users.locale')
            ->select(['content_language_checks.id', 'content_language_checks.ai_language', 'users.locale as author_locale'])
            ->lazyById(1000, 'content_language_checks.id', 'id') as $check) {
            $authored++;
            $sameAsAuthor += (int) ($check->ai_language === $check->getAttribute('author_locale'));
        }

        $this->line("A post's text was in its author's language in {$sameAsAuthor} of {$authored} posts (".$this->percent($sameAsAuthor, max(1, $authored)).').');

        return self::SUCCESS;
    }

    private function percent(int $part, int $whole): string
    {
        return round(100 * $part / $whole).'%';
    }
}
