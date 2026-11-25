<?php

namespace App\Console\Commands;

use App\Actions\Languages\LanguageCheckQueue;
use App\Actions\Languages\WorkOutPostLanguageAction;
use App\Exceptions\Languages\LanguageDetectionFailed;
use App\Models\Post;
use App\Services\Languages\OpenAiLanguageDetector;
use App\Support\Languages\TextCleaner;
use App\Support\Observability\DomainLogger;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keeps the languages of posts and comments current (docs/architecture/language-ranking.md). Every minute, in a
 * process of its own — never on the job queue's one worker — and each step on its own time:
 *
 * 1. Posts marked stale — by a vote, a comment, an author's change of language or a check below — have their
 *    language worked out again, first, so that nothing below can starve it.
 * 2. Texts written before languages were kept are read on this server, a few hundred a run, and queued — so an
 *    installation fills in its older posts and comments on its own after the release that brings languages.
 * 3. The checks of texts written lately are asked of OpenAI in batches, texts written now before older ones;
 *    each answer replaces the local detector's and marks its post. A run that finds OpenAI unreachable stops asking
 *    until the next one. Off by setting, without the Translation AI key, or once the day's limit is reached.
 */
final class WorkOnLanguagesCommand extends Command
{
    protected $signature = 'languages:work';

    protected $description = 'Work out the languages of posts again and have OpenAI check the languages of new texts.';

    private const int POSTS = 200;

    /** Older texts read a run: the server reads one in about a millisecond. */
    private const int OLDER_TEXTS = 500;

    private const int POST_SECONDS = 15;

    private const int CHECK_SECONDS = 25;

    public function handle(
        WorkOutPostLanguageAction $workOut,
        LanguageCheckQueue $queue,
        OpenAiLanguageDetector $openAi,
        ProjectSettingsManager $settings,
        DomainLogger $log,
    ): int {
        $posts = $this->workOutStalePosts($workOut);
        $older = $this->readOlderTexts($queue);
        $checked = 0;
        $ranking = $settings->current()->languageRanking();

        if ($ranking->aiChecks && $openAi->configured()) {
            $checked = $this->checkTexts($queue, $openAi, $ranking->aiChecksPerDay, $log);
        }

        $this->info("Worked out {$posts} posts, read {$older} older texts, checked {$checked} texts.");

        return self::SUCCESS;
    }

    private function workOutStalePosts(WorkOutPostLanguageAction $workOut): int
    {
        $deadline = microtime(true) + self::POST_SECONDS;
        $done = 0;

        $ids = Post::withTrashed()->whereNotNull('language_stale_at')->orderBy('language_stale_at')->orderBy('id')->limit(self::POSTS)->pluck('id');

        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }

            try {
                $workOut->handle((int) $id);
                $done++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $done;
    }

    private function readOlderTexts(LanguageCheckQueue $queue): int
    {
        try {
            return $queue->readOlderTexts(self::OLDER_TEXTS);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    private function checkTexts(LanguageCheckQueue $queue, OpenAiLanguageDetector $openAi, int $perDay, DomainLogger $log): int
    {
        $deadline = microtime(true) + self::CHECK_SECONDS;
        $checked = 0;
        $queue->reclaimAbandoned();

        while (microtime(true) < $deadline) {
            $room = min(OpenAiLanguageDetector::BATCH, $perDay - $queue->sentToday());

            if ($room <= 0) {
                break;
            }

            $claimed = $queue->claim($room);

            if ($claimed->isEmpty()) {
                break;
            }

            $texts = array_map(fn (?string $text): string => TextCleaner::clean((string) $text), $queue->texts($claimed));

            try {
                $answers = $openAi->detect($texts);
            } catch (LanguageDetectionFailed $failure) {
                $queue->release($claimed);
                $log->info('languages.check.failed', ['checks' => $claimed->count(), 'reason' => $failure->getMessage()]);

                if ($failure->unavailable()) {
                    break;
                }

                continue;
            }

            foreach ($claimed as $check) {
                if (! array_key_exists($check->id, $answers)) {
                    $queue->release(collect([$check]));

                    continue;
                }

                try {
                    $queue->settle($check, $answers[$check->id]['language'], $answers[$check->id]['confidence']);
                    $checked++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $checked;
    }
}
