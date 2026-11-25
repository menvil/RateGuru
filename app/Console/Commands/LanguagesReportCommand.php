<?php

namespace App\Console\Commands;

use App\Queries\Languages\LanguageStatusQuery;
use Illuminate\Console\Command;

/**
 * What the languages of posts and comments look like on this installation (docs/architecture/language-ranking.md):
 * how many published posts each language has, where the checks of texts stand, how often the server's own reading
 * agreed with OpenAI's, and how often a post's text is in its author's language — the numbers the weights of a
 * post's language were chosen on, read on real posts. Project settings → Language ranking shows the same.
 */
final class LanguagesReportCommand extends Command
{
    protected $signature = 'languages:report';

    protected $description = 'Report the languages of posts and how well they are told.';

    public function handle(LanguageStatusQuery $status): int
    {
        $posts = $status->postsByLanguage();
        $total = max(1, array_sum($posts));

        $this->info('Published posts by language');
        $this->table(['Language', 'Posts', 'Share'], array_map(fn (string $language, int $count): array => [$language, $count, $this->percent($count, $total)], array_keys($posts), $posts));

        $this->info('Checks of texts');
        $this->table(['Status', 'Checks'], array_map(fn (string $state, int $count): array => [$state, $count], array_keys($status->checks()), $status->checks()));

        ['agreed' => $agreed, 'compared' => $compared] = $status->readingsAgreed();
        $this->line("The server's own reading agreed with OpenAI on {$agreed} of {$compared} texts both read (".$this->percent($agreed, max(1, $compared)).').');

        ['agreed' => $agreed, 'compared' => $compared] = $status->textsInTheirAuthorsLanguage();
        $this->line("A post's text was in its author's language in {$agreed} of {$compared} posts (".$this->percent($agreed, max(1, $compared)).').');

        return self::SUCCESS;
    }

    private function percent(int $part, int $whole): string
    {
        return round(100 * $part / $whole).'%';
    }
}
