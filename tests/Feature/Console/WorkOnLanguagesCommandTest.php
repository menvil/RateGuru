<?php

use App\Enums\ContentLanguageCheckPriority;
use App\Enums\ContentLanguageCheckStatus;
use App\Models\Comment;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * languages:work keeps the languages of posts and comments current: stale posts first, then the checks of new
 * texts asked of OpenAI, texts written now before a backfill's, within the day's limit, and not at all while
 * OpenAI is unreachable, switched off or not set up.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'translation.providers.openai.api_key' => 'test-key',
        'translation.providers.openai.model' => 'gpt-test',
        'translation.providers.openai.base_url' => 'https://api.openai.test/v1',
    ]);
});

/** OpenAI answering every text it is asked about with one language. */
function openAiAnswering(string $language): void
{
    Http::fake(['api.openai.test/*' => function (Request $request) use ($language) {
        $ids = array_map(fn (string $line): string => json_decode($line, true)['id'], explode("\n", $request['input'][0]['content'][0]['text']));

        return Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
            'results' => array_map(fn (string $id): array => ['id' => $id, 'language' => $language, 'confidence' => 0.97], $ids),
        ])]]]]]);
    }]);
}

/** A post whose text was already read and told nothing: languages:work leaves its text alone. */
function postAlreadyRead(array $attributes = []): Post
{
    $post = Post::factory()->create($attributes);
    ContentLanguageCheck::query()->create(['post_id' => $post->id, 'status' => ContentLanguageCheckStatus::Skipped, 'run_after' => now(), 'finished_at' => now()]);

    return $post;
}

function pendingCheck(array $text, ContentLanguageCheckPriority $priority = ContentLanguageCheckPriority::Live): ContentLanguageCheck
{
    return ContentLanguageCheck::query()->create([...$text, 'local_language' => 'bg', 'local_weight' => 1, 'priority' => $priority, 'run_after' => now()->subMinute()]);
}

it('works out stale posts again', function () {
    $author = User::factory()->create();
    $author->forceFill(['locale' => 'ru'])->save();
    $post = postAlreadyRead(['user_id' => $author->id, 'language' => null, 'language_stale_at' => now()]);

    $this->artisan('languages:work')->assertSuccessful();

    expect($post->fresh()->language)->toBe('ru')
        ->and($post->fresh()->language_stale_at)->toBeNull();
});

it('settles each check with OpenAI\'s answer, gives a comment that language and marks its post', function () {
    openAiAnswering('uk');
    $post = Post::factory()->create(['title' => 'Гарне фото']);
    $comment = Comment::factory()->create(['post_id' => $post->id, 'body' => 'Дуже гарно, дякую', 'language' => 'bg']);
    $postCheck = pendingCheck(['post_id' => $post->id]);
    $commentCheck = pendingCheck(['comment_id' => $comment->id]);

    $this->artisan('languages:work')->assertSuccessful();

    expect($postCheck->fresh()->status)->toBe(ContentLanguageCheckStatus::Done)
        ->and($postCheck->fresh()->ai_language)->toBe('uk')
        ->and($commentCheck->fresh()->ai_confidence)->toBe(0.97)
        ->and($comment->fresh()->language)->toBe('uk')
        ->and($post->fresh()->language_stale_at)->not->toBeNull();

    Http::assertSentCount(1);
});

it('sends only the words of a text', function () {
    openAiAnswering('en');
    $post = Post::factory()->create(['title' => 'Look @anna_92 https://example.com/x 😍', 'description' => null]);
    pendingCheck(['post_id' => $post->id]);

    $this->artisan('languages:work')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => json_decode($request['input'][0]['content'][0]['text'], true)['text'] === 'Look');
});

it('asks about texts written now before a backfill\'s', function () {
    openAiAnswering('en');
    ProjectSettings::factory()->create(['language_ranking' => ['ai_checks_per_day' => 1]]);
    $backfill = pendingCheck(['post_id' => Post::factory()->create()->id], ContentLanguageCheckPriority::Backfill);
    $live = pendingCheck(['post_id' => Post::factory()->create()->id]);

    $this->artisan('languages:work')->assertSuccessful();

    expect($live->fresh()->status)->toBe(ContentLanguageCheckStatus::Done)
        ->and($backfill->fresh()->status)->toBe(ContentLanguageCheckStatus::Pending);
});

it('stops at the day\'s limit', function () {
    openAiAnswering('en');
    ProjectSettings::factory()->create(['language_ranking' => ['ai_checks_per_day' => 2]]);
    $checks = collect(range(1, 3))->map(fn () => pendingCheck(['post_id' => Post::factory()->create()->id]));

    $this->artisan('languages:work')->assertSuccessful();

    expect($checks->map(fn (ContentLanguageCheck $check) => $check->fresh()->status)->filter(fn ($status) => $status === ContentLanguageCheckStatus::Done))->toHaveCount(2);
});

it('still works out stale posts while OpenAI hangs, and stops asking it for the run', function () {
    $calls = 0;
    Http::fake(['api.openai.test/*' => function () use (&$calls) {
        $calls++;

        throw new ConnectionException('timed out');
    }]);
    $stale = postAlreadyRead(['language_stale_at' => now()]);
    $check = pendingCheck(['post_id' => Post::factory()->create()->id]);
    pendingCheck(['post_id' => Post::factory()->create()->id])->forceFill(['attempts' => 1])->save();

    $this->artisan('languages:work')->assertSuccessful();

    expect($stale->fresh()->language_stale_at)->toBeNull()
        ->and($check->fresh()->status)->toBe(ContentLanguageCheckStatus::Pending)
        ->and($check->fresh()->attempts)->toBe(1)
        ->and($check->fresh()->run_after->isFuture())->toBeTrue()
        // The retry waiting behind it is not tried in the same run.
        ->and($calls)->toBe(1);
});

it('retries a check alone, and leaves it on the local answer after its last attempt', function () {
    Http::fake(['api.openai.test/*' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No.']]]]])]);
    $check = pendingCheck(['post_id' => Post::factory()->create()->id]);
    $check->forceFill(['attempts' => 2])->save();
    pendingCheck(['post_id' => Post::factory()->create()->id])->forceFill(['run_after' => now()->addHour()])->save();

    $this->artisan('languages:work')->assertSuccessful();

    expect($check->fresh()->status)->toBe(ContentLanguageCheckStatus::Failed)
        ->and($check->fresh()->language())->toBe('bg');
    Http::assertSent(fn (Request $request): bool => substr_count($request['input'][0]['content'][0]['text'], "\n") === 0);
});

it('asks nothing while the checks are switched off or the key is missing', function (Closure $setUp) {
    $setUp();
    $check = pendingCheck(['post_id' => Post::factory()->create()->id]);

    $this->artisan('languages:work')->assertSuccessful();

    expect($check->fresh()->status)->toBe(ContentLanguageCheckStatus::Pending);
    Http::assertNothingSent();
})->with([
    'switched off' => [fn () => ProjectSettings::factory()->create(['language_ranking' => ['ai_checks' => false]])],
    'no key' => [fn () => config(['translation.providers.openai.api_key' => null])],
]);

it('takes up a claim a run left behind', function () {
    openAiAnswering('en');
    $check = pendingCheck(['post_id' => Post::factory()->create()->id]);
    $check->forceFill(['status' => ContentLanguageCheckStatus::Sending, 'attempts' => 1, 'claimed_at' => now()->subMinutes(30)])->save();

    $this->artisan('languages:work')->assertSuccessful();

    expect($check->fresh()->status)->toBe(ContentLanguageCheckStatus::Done);
});

it('runs every minute in a process of its own, a stuck run lapsing within minutes', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'languages:work'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->expiresAt)->toBe(5)
        ->and($events->first()->runInBackground)->toBeTrue();
});

it('takes up the texts written before languages were kept on its own, behind the texts written now', function () {
    $author = User::factory()->create();
    $author->forceFill(['locale' => 'en'])->save();
    $post = Post::factory()->published()->create(['user_id' => $author->id, 'title' => 'Wunderschönes Foto aus den Bergen', 'description' => 'Gefällt mir sehr']);
    $comment = Comment::factory()->create(['post_id' => $post->id, 'body' => 'Wirklich ein tolles Bild, danke']);
    config(['translation.providers.openai.api_key' => null]);

    $this->artisan('languages:work')->expectsOutputToContain('read 2 older texts')->assertSuccessful();

    expect(ContentLanguageCheck::query()->where('post_id', $post->id)->sole()->priority)->toBe(ContentLanguageCheckPriority::Backfill)
        ->and($comment->fresh()->language)->toBe('de')
        ->and($post->fresh()->language_stale_at)->not->toBeNull();

    $this->artisan('languages:work')->expectsOutputToContain('read 0 older texts')->assertSuccessful();

    expect($post->fresh()->language)->toBe('de')
        ->and($post->fresh()->language_stale_at)->toBeNull();
});
