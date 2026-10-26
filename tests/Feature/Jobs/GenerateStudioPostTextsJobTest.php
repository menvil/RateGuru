<?php

use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Enums\StudioImageAnalysisStatus;
use App\Enums\StudioPlanStatus;
use App\Enums\StudioPostTextGenerationError;
use App\Enums\StudioPostTextGenerationStatus;
use App\Enums\StudioPostTextTone;
use App\Enums\UserStatus;
use App\Jobs\Studio\GenerateStudioPostTextsJob;
use App\Models\MediaAsset;
use App\Models\PersonaProfile;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\StudioPlanTextGeneration;
use App\Models\User;
use Database\Factories\StudioPlanImageAnalysisFactory;
use Database\Factories\StudioPlanTextGenerationFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * The queued job behind a batch of post text suggestions: it claims its
 * batch before anything else, so a job delivered twice sends nothing; it
 * checks the plan, its photo and its analysis as they are now; it makes one
 * request, from the stored analysis, with no transaction open; and it keeps
 * the texts only while they still fit the plan — never touching the plan,
 * its version or its final texts, and never an earlier batch.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioImageAnalysis();
    $this->admin = User::factory()->admin()->create();
});

it('runs within the queue\'s timings, once, failing on its timeout', function () {
    $job = new GenerateStudioPostTextsJob(1, 'attempt');

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(75)
        ->and($job->failOnTimeout)->toBeTrue();
});

it('writes the batch and keeps ten titles and ten descriptions with the call\'s metadata, the plan untouched', function () {
    $plan = studioPlanWithFinishedAnalysis(['title' => 'Typed by hand', 'description' => 'Kept as it is', 'lock_version' => 3]);
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);

    runStudioPostTextsJob($generation);

    expect($generation->refresh())
        ->status->toBe(StudioPostTextGenerationStatus::Completed)
        ->titles->toBe(StudioPlanTextGenerationFactory::titles())
        ->descriptions->toBe(StudioPlanTextGenerationFactory::descriptions())
        ->model->toBe('gpt-4.1-mini-2025-04-14')
        ->request_id->toBe('resp_studio_texts_test')
        ->input_tokens->toBe(120)
        ->output_tokens->toBe(30)
        ->total_tokens->toBe(150)
        ->error_code->toBeNull()
        ->and($generation->finished_at)->not->toBeNull()
        ->and($plan->fresh()->only(['title', 'description', 'lock_version']))->toBe(['title' => 'Typed by hand', 'description' => 'Kept as it is', 'lock_version' => 3]);
    Http::assertSentCount(1);
});

it('sends the analysis as it is stored now, and never the photo', function () {
    $plan = studioPlanWithFinishedAnalysis(description: StudioPlanImageAnalysisFactory::description(['summary' => 'A grey cat sleeps on a windowsill.']));
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);

    runStudioPostTextsJob($generation);

    Http::assertSent(fn ($request): bool => str_contains($request->data()['input'][0]['content'][0]['text'], 'A grey cat sleeps on a windowsill.')
        && ! str_contains(json_encode($request->data()), 'input_image'));
});

it('writes in the voice of the post’s author — its name and how it writes, as data — and nothing private of its account', function () {
    $author = studioPersonaWithProfile([
        'short_description' => 'A dry-witted observer of the obvious.',
        'character' => 'Sarcastic, observant and a little ridiculous.',
        'speech_style' => 'Short deadpan lines. Rarely an exclamation mark.',
        'topics' => 'Double meanings, internet culture',
        'never_writes_about' => 'Politics',
        'emoji_frequency' => PersonaEmojiFrequency::Occasional,
        'engagement' => PersonaEngagement::High,
        'max_replies_per_day' => 9,
    ], ['display_name' => 'Deadpan Dora', 'username' => 'deadpan_dora']);
    $plan = studioPlanWithFinishedAnalysis(['author_user_id' => $author->id]);
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);

    runStudioPostTextsJob($generation);

    Http::assertSent(function ($request) use ($author): bool {
        $text = $request->data()['input'][0]['content'][0]['text'];
        preg_match('~<post_author>\n(.*)\n</post_author>~s', $text, $block);

        expect(json_decode($block[1] ?? 'null', true))->toBe([
            'name' => 'Deadpan Dora',
            'short_description' => 'A dry-witted observer of the obvious.',
            'character' => 'Sarcastic, observant and a little ridiculous.',
            'speech_style' => 'Short deadpan lines. Rarely an exclamation mark.',
            'topics_and_jokes' => 'Double meanings, internet culture',
            'never_writes_about' => 'Politics',
            'emoji' => 'sometimes',
        ])
            ->and($text)->toContain("Write 10 titles and 10 descriptions in the Cheeky tone. Write them in the author's voice.")
            ->and($request->data()['instructions'])->toContain('With an author, write as that author')
            ->and(json_encode($request->data()))->not->toContain($author->email)
            ->not->toContain('deadpan_dora')
            ->not->toContain('engagement')
            ->not->toContain('max_replies')
            ->not->toContain('password')
            ->not->toContain('"id"');

        return true;
    });
    expect($generation->refresh()->status)->toBe(StudioPostTextGenerationStatus::Completed);
});

it('writes as the site’s own for a post with no author, or one whose author is no persona to lend a voice', function (Closure $author) {
    $plan = studioPlanWithFinishedAnalysis(['author_user_id' => $author()?->id]);
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);

    runStudioPostTextsJob($generation);

    Http::assertSent(fn ($request): bool => ! str_contains($request->data()['input'][0]['content'][0]['text'], '<post_author>')
        && str_ends_with($request->data()['input'][0]['content'][0]['text'], 'Write 10 titles and 10 descriptions in the Cheeky tone.'));
    expect($generation->refresh()->status)->toBe(StudioPostTextGenerationStatus::Completed);
})->with([
    'no author' => [fn () => null],
    'a deleted persona' => [fn () => studioPersonaWithProfile(account: ['status' => UserStatus::Deleted])],
]);

it('sends nothing, and ends the batch, when the author or how the author writes changed before the job ran', function (Closure $change) {
    $author = studioPersonaWithProfile();
    $plan = studioPlanWithFinishedAnalysis(['author_user_id' => $author->id]);
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake();
    $change($plan, $author);

    runStudioPostTextsJob($generation);

    expect($generation->refresh())->status->toBe(StudioPostTextGenerationStatus::Failed)->error_code->toBe(StudioPostTextGenerationError::StaleAnalysis);
    Http::assertNothingSent();
})->with([
    'another author' => [fn (StudioPlan $plan) => $plan->forceFill(['author_user_id' => studioPersonaWithProfile()->id])->save()],
    'no author any more' => [fn (StudioPlan $plan) => $plan->forceFill(['author_user_id' => null])->save()],
    'the author’s character rewritten' => [fn (StudioPlan $plan, User $author) => PersonaProfile::query()->where('user_id', $author->id)->update(['character' => 'Someone else entirely.'])],
    'the author’s emoji habit changed' => [fn (StudioPlan $plan, User $author) => PersonaProfile::query()->where('user_id', $author->id)->update(['emoji_frequency' => 'frequent'])],
    'the author renamed' => [fn (StudioPlan $plan, User $author) => $author->forceFill(['display_name' => 'A new name'])->save()],
]);

it('drops texts that came back after the author, or how the author writes, changed', function (Closure $change) {
    $author = studioPersonaWithProfile();
    $plan = studioPlanWithFinishedAnalysis(['author_user_id' => $author->id]);
    $generation = requestStudioPostTexts($this->admin, $plan);

    Http::fake(function () use ($plan, $author, $change) {
        $change($plan, $author);

        return Http::response(studioPostTextsBody());
    });

    runStudioPostTextsJob($generation);

    expect($generation->refresh())->status->toBe(StudioPostTextGenerationStatus::Failed)
        ->error_code->toBe(StudioPostTextGenerationError::StaleAnalysis)
        ->titles->toBeNull()
        // The tokens were spent either way.
        ->total_tokens->toBe(150);
    Http::assertSentCount(1);
})->with([
    'another author' => [fn (StudioPlan $plan) => $plan->forceFill(['author_user_id' => studioPersonaWithProfile()->id])->save()],
    'the author’s speech style rewritten' => [fn (StudioPlan $plan, User $author) => PersonaProfile::query()->where('user_id', $author->id)->update(['speech_style' => 'Long, ornate paragraphs.'])],
    'the author’s limits rewritten' => [fn (StudioPlan $plan, User $author) => PersonaProfile::query()->where('user_id', $author->id)->update(['never_writes_about' => 'Cats'])],
]);

it('keeps the texts when only what the model is not told of the author changed', function (Closure $change) {
    $author = studioPersonaWithProfile();
    $plan = studioPlanWithFinishedAnalysis(['author_user_id' => $author->id]);
    $generation = requestStudioPostTexts($this->admin, $plan);

    Http::fake(function () use ($author, $change) {
        $change($author);

        return Http::response(studioPostTextsBody());
    });

    runStudioPostTextsJob($generation);

    expect($generation->refresh()->status)->toBe(StudioPostTextGenerationStatus::Completed);
})->with([
    'its public bio' => [fn (User $author) => $author->forceFill(['bio' => 'A new bio.'])->save()],
    'its engagement and daily replies' => [fn (User $author) => PersonaProfile::query()->where('user_id', $author->id)->update(['engagement' => 'high', 'max_replies_per_day' => 20])],
]);

it('sends a batch asked in Spicy in that tone, with the rule that it is no word about anyone\'s age', function () {
    $plan = studioPlanWithFinishedAnalysis(['author_user_id' => studioPersonaWithProfile()->id]);
    $generation = requestStudioPostTexts($this->admin, $plan, StudioPostTextTone::Spicy);
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);

    runStudioPostTextsJob($generation);

    Http::assertSent(function ($request): bool {
        $instructions = $request->data()['instructions'];

        expect($instructions)->toContain('Tone: '.StudioPostTextTone::Spicy->instruction())
            ->toContain('not the tone, which — Spicy included — sets the mood of the text and is never a fact about anyone\'s age')
            ->toContain('So make no sexualised remark about a person shown')
            ->and($request->data()['input'][0]['content'][0]['text'])->toContain('in the Spicy / Adult humour tone.');

        return true;
    });
    expect($generation->refresh())->tone->toBe(StudioPostTextTone::Spicy)->status->toBe(StudioPostTextGenerationStatus::Completed);
});

it('sends nothing when the job is delivered again, or after its batch has ended', function () {
    $plan = studioPlanWithFinishedAnalysis();
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);

    runStudioPostTextsJob($generation);
    runStudioPostTextsJob($generation);

    Http::assertSentCount(1);

    $failed = StudioPlanTextGeneration::factory()->forPlan($plan)->failed()->create();
    runStudioPostTextsJob($failed);

    Http::assertSentCount(1);
});

it('sends nothing, and ends the batch with the reason, when the plan changed before the job ran', function (Closure $change, StudioPostTextGenerationError $error) {
    $plan = studioPlanWithFinishedAnalysis();
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake();
    $change($plan);

    runStudioPostTextsJob($generation);

    expect($generation->refresh())->status->toBe(StudioPostTextGenerationStatus::Failed)->error_code->toBe($error);
    Http::assertNothingSent();
})->with([
    'past its Draft' => [fn (StudioPlan $plan) => $plan->forceFill(['status' => StudioPlanStatus::Approved])->save(), StudioPostTextGenerationError::PlanClosed],
    'a new photo' => [fn (StudioPlan $plan) => $plan->forceFill(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])->save(), StudioPostTextGenerationError::StaleAnalysis],
    'an analysis with another result' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::query()->update(['result' => json_encode(StudioPlanImageAnalysisFactory::description(['summary' => 'Something else entirely.']))]), StudioPostTextGenerationError::StaleAnalysis],
    'not configured any more' => [fn () => configureStudioImageAnalysis(['enabled' => false]), StudioPostTextGenerationError::NotConfigured],
]);

it('sends nothing for a plan deleted before the job ran, and leaves nothing behind', function () {
    $plan = studioPlanWithFinishedAnalysis();
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake();
    StudioPlan::query()->whereKey($plan->id)->delete();

    runStudioPostTextsJob($generation);

    Http::assertNothingSent();
    expect(StudioPlanTextGeneration::query()->exists())->toBeFalse();
});

it('ends the batch with the provider\'s reason, keeping every earlier batch and the plan as they were', function (int $status, StudioPostTextGenerationError $error) {
    $plan = studioPlanWithFinishedAnalysis(['title' => 'Mine']);
    $earlier = StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create();
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'no']], $status)]);

    runStudioPostTextsJob($generation);

    expect($generation->refresh())->status->toBe(StudioPostTextGenerationStatus::Failed)->error_code->toBe($error)->titles->toBeNull()
        ->and($earlier->fresh())->status->toBe(StudioPostTextGenerationStatus::Completed)->titles->toBe(StudioPlanTextGenerationFactory::titles())
        ->and($plan->fresh()->only(['title', 'lock_version']))->toBe(['title' => 'Mine', 'lock_version' => 1]);
})->with([
    'rate limited' => [429, StudioPostTextGenerationError::RateLimited],
    'unavailable' => [503, StudioPostTextGenerationError::ProviderUnavailable],
    'a key refused' => [401, StudioPostTextGenerationError::AuthenticationFailed],
]);

it('keeps nothing of an answer that is not a valid batch', function () {
    $plan = studioPlanWithFinishedAnalysis();
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody(titles: array_slice(StudioPlanTextGenerationFactory::titles(), 0, 7)))]);

    runStudioPostTextsJob($generation);

    expect($generation->refresh())->status->toBe(StudioPostTextGenerationStatus::Failed)
        ->error_code->toBe(StudioPostTextGenerationError::InvalidProviderResponse)
        ->titles->toBeNull()->descriptions->toBeNull()
        // The tokens were spent either way.
        ->total_tokens->toBe(150);
});

it('holds no transaction open while it waits for the provider', function () {
    $plan = studioPlanWithFinishedAnalysis();
    $generation = requestStudioPostTexts($this->admin, $plan);
    $outside = DB::transactionLevel();
    $during = null;

    Http::fake(function () use (&$during) {
        $during = DB::transactionLevel();

        return Http::response(studioPostTextsBody());
    });

    runStudioPostTextsJob($generation);

    expect($during)->toBe($outside);
});

it('drops texts that came back after the photo or its analysis changed', function (Closure $change) {
    $plan = studioPlanWithFinishedAnalysis();
    $generation = requestStudioPostTexts($this->admin, $plan);

    Http::fake(function () use ($plan, $change) {
        $change($plan);

        return Http::response(studioPostTextsBody());
    });

    runStudioPostTextsJob($generation);

    expect($generation->refresh())->status->toBe(StudioPostTextGenerationStatus::Failed)
        ->error_code->toBe(StudioPostTextGenerationError::StaleAnalysis)
        ->titles->toBeNull();
})->with([
    'a new photo' => [fn (StudioPlan $plan) => $plan->forceFill(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])->save()],
    'the analysis written again, differently' => [fn () => StudioPlanImageAnalysis::query()->update(['result' => json_encode(StudioPlanImageAnalysisFactory::description(['summary' => 'Not the same any more.']))])],
    'the analysis gone for the new photo' => [fn () => StudioPlanImageAnalysis::query()->update(['source_image_asset_id' => MediaAsset::factory()->postImage()->create()->id])],
]);

it('keeps the texts when only the plan\'s texts were saved meanwhile, or the analysis re-run gave the same result', function (Closure $change) {
    $plan = studioPlanWithFinishedAnalysis();
    $generation = requestStudioPostTexts($this->admin, $plan);

    Http::fake(function () use ($plan, $change) {
        $change($plan);

        return Http::response(studioPostTextsBody());
    });

    runStudioPostTextsJob($generation);

    expect($generation->refresh()->status)->toBe(StudioPostTextGenerationStatus::Completed);
})->with([
    'a save of the texts' => [fn (StudioPlan $plan) => $plan->forceFill(['title' => 'Saved meanwhile', 'lock_version' => 2])->save()],
    'a re-run on its way, its result unchanged' => [fn () => StudioPlanImageAnalysis::query()->update(['status' => StudioImageAnalysisStatus::Queued->value])],
]);

it('ends its batch, and only its own unfinished one, when the job fails', function () {
    $plan = studioPlanWithFinishedAnalysis();
    $earlier = StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create();
    $generation = requestStudioPostTexts($this->admin, $plan);

    (new GenerateStudioPostTextsJob($generation->id, $generation->attempt_id))->failed(new RuntimeException('Timed out'));
    (new GenerateStudioPostTextsJob($earlier->id, $earlier->attempt_id))->failed(new RuntimeException('Late'));

    expect($generation->refresh())->status->toBe(StudioPostTextGenerationStatus::Failed)->error_code->toBe(StudioPostTextGenerationError::UnexpectedFailure)
        ->and($earlier->fresh()->status)->toBe(StudioPostTextGenerationStatus::Completed);
});

it('logs the batch\'s start and end with metadata only — no key, no analysis, no texts', function () {
    Log::spy();
    $plan = studioPlanWithFinishedAnalysis();
    $generation = requestStudioPostTexts($this->admin, $plan);
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);

    runStudioPostTextsJob($generation);

    $logged = [];
    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use (&$logged): bool {
        $logged[$message] = $context;

        return true;
    });

    expect(array_keys($logged))->toContain('studio.post_text_generation.started', 'studio.post_text_generation.completed')
        ->and($logged['studio.post_text_generation.completed'])->toMatchArray([
            'studio_plan_id' => $plan->id,
            'attempt_id' => $generation->attempt_id,
            'model' => 'gpt-4.1-mini-2025-04-14',
            'provider_request_id' => 'resp_studio_texts_test',
            'total_tokens' => 150,
        ]);

    $everything = json_encode($logged);

    expect($everything)->not->toContain(STUDIO_ANALYSIS_TEST_API_KEY)
        ->not->toContain('brown dog')
        ->not->toContain('Beach zoomies')
        ->not->toContain('sprints across the sand');
});

it('logs a failure with its reason', function () {
    Log::spy();
    $plan = studioPlanWithFinishedAnalysis();
    $failing = requestStudioPostTexts($this->admin, $plan);
    Http::fake(['api.openai.com/*' => Http::response([], 429)]);
    runStudioPostTextsJob($failing);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'studio.post_text_generation.failed'
        && $context['error_code'] === 'rate_limited'
        && $context['attempt_id'] === $failing->attempt_id);
});

it('logs texts dropped for a changed photo as discarded', function () {
    Log::spy();
    $plan = studioPlanWithFinishedAnalysis();
    $dropped = requestStudioPostTexts($this->admin, $plan);
    Http::fake(function () use ($plan) {
        $plan->forceFill(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])->save();

        return Http::response(studioPostTextsBody());
    });
    runStudioPostTextsJob($dropped);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.post_text_generation.discarded'
        && $context['reason'] === 'analysis_changed'
        && $context['error_code'] === 'stale_analysis');
});
