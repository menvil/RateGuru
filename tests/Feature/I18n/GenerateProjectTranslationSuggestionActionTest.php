<?php

use App\Actions\Translations\GenerateProjectTranslationSuggestionAction;
use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Exceptions\Translations\CannotSuggestTranslationException;
use App\Filament\Pages\TranslationCenterPage;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationRequestFactory;
use App\Support\Translations\ProjectTranslationSuggestion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * An AI suggestion for one missing translation: the unit found again in the
 * catalog, described to the engine as a batch of one, and the result handed
 * back — never stored. Every refusal happens before the engine is asked.
 */
function suggestTranslation(mixed $unit, mixed $locale, ?User $actor = null, mixed $stored = ''): ProjectTranslationSuggestion
{
    return app(GenerateProjectTranslationSuggestionAction::class)->handle($actor ?? User::factory()->admin()->create(), $unit, $locale, $stored);
}

/** Why no suggestion was made, as "reason: message", or null when one was. */
function suggestionRefusal(Closure $suggest): ?string
{
    try {
        $suggest();
    } catch (CannotSuggestTranslationException $exception) {
        return $exception->reason.': '.$exception->getMessage();
    }

    return null;
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    Http::preventStrayRequests();
});

// A suggestion -----------------------------------------------------------------------

it('suggests a translation for a missing unit, through one batch of one item', function () {
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(answeringTranslationProvider(['Грузинская кухня']));
    $category = untranslatedCategory();
    Carbon::setTestNow('2026-10-07 09:41:00');

    $suggestion = suggestTranslation("categories:{$category->id}:name", $target);

    expect($suggestion->unitId)->toBe("categories:{$category->id}:name")
        ->and($suggestion->locale)->toBe($target)
        ->and($suggestion->text)->toBe('Грузинская кухня')
        ->and($suggestion->provider)->toBe('scripted')
        ->and($suggestion->model)->toBe('scripted-model')
        ->and($suggestion->generatedAt->format(DATE_ATOM))->toBe(Carbon::parse('2026-10-07 09:41:00')->format(DATE_ATOM))
        ->and($provider->received)->toHaveCount(1)
        ->and($provider->received[0]->itemIds())->toBe(["categories:{$category->id}:name"])
        ->and($provider->received[0]->dataClassification)->toBe(TranslationDataClassification::PublicContent);
});

it('sends exactly the request the project factory builds, nothing from the caller', function () {
    [$target, $other] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(answeringTranslationProvider(['Собаки']));
    $category = Category::factory()->create(['slug' => 'dogs', 'name' => 'Dogs', 'name_translations' => [$other => 'Кучета'], 'is_active' => true]);
    $unit = "categories:{$category->id}:name";

    suggestTranslation($unit, $target);

    $expected = app(ProjectTranslationRequestFactory::class)->make([app(ProjectTranslationCatalog::class)->find($unit)], $target);

    expect($provider->received[0])->toEqual($expected)
        ->and($provider->received[0]->items[0]->existingTranslations)->toBe([$other => 'Кучета']);
});

it('stores nothing: the translation stays missing until an administrator saves it', function () {
    [$target] = twoTranslatedLocales();
    useScriptedTranslationProvider(answeringTranslationProvider(['Грузинская кухня']));
    $category = untranslatedCategory();
    $unit = "categories:{$category->id}:name";
    $admin = User::factory()->admin()->create();

    $suggestion = suggestTranslation($unit, $target, $admin);

    expect($category->fresh()->name_translations)->toBeNull();

    // Only the existing save makes it project content.
    app(UpdateProjectTranslationAction::class)->handle($admin, $unit, $target, $suggestion->text);

    expect($category->fresh()->name_translations)->toBe([$target => 'Грузинская кухня']);
});

it('suggests into an installed language that is not offered to visitors yet', function () {
    [$target, $disabled] = twoTranslatedLocales();
    offerLocales([$target]);
    useScriptedTranslationProvider(answeringTranslationProvider(['Грузинска кухня']));
    $category = untranslatedCategory();

    expect(suggestTranslation("categories:{$category->id}:name", $disabled)->text)->toBe('Грузинска кухня');
});

it('offers an alternative to a saved translation, which stays stored until the alternative is saved', function () {
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(answeringTranslationProvider(['Псы']));
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$target => 'Собаки'], 'is_active' => true]);
    $unit = "categories:{$category->id}:name";
    $admin = User::factory()->admin()->create();

    $suggestion = suggestTranslation($unit, $target, $admin, 'Собаки');

    // The saved text is only compared: what is sent is the factory's request, the target left out of the context.
    expect($suggestion->text)->toBe('Псы')
        ->and($provider->received[0]->items[0]->existingTranslations)->not->toHaveKey($target)
        ->and(json_encode($provider->received[0]))->not->toContain('Собаки')
        ->and($category->fresh()->name_translations)->toBe([$target => 'Собаки']);

    app(UpdateProjectTranslationAction::class)->handle($admin, $unit, $target, $suggestion->text);

    expect($category->fresh()->name_translations)->toBe([$target => 'Псы']);
});

it('refuses an alternative once the saved translation has changed, without asking the engine', function (array $stored, string $seen) {
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => $stored === [] ? null : [$target => $stored[0]], 'is_active' => true]);

    expect(suggestionRefusal(fn () => suggestTranslation("categories:{$category->id}:name", $target, stored: $seen)))
        ->toBe('changed: This translation was changed by someone else. Reload the page to review it.')
        ->and($provider->received)->toBe([]);
})->with([
    'edited by someone else' => [['Псы'], 'Собаки'],
    'removed by someone else' => [[], 'Собаки'],
]);

it('treats anything but text as no stored translation at all', function () {
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(answeringTranslationProvider(['Грузинская кухня']));
    $category = untranslatedCategory();

    expect(suggestTranslation("categories:{$category->id}:name", $target, stored: ['Собаки'])->text)->toBe('Грузинская кухня')
        ->and($provider->received)->toHaveCount(1);
});

// Refused before the engine is asked ----------------------------------------------------

it('refuses what it cannot suggest, without asking the engine', function (Closure $arguments, string $reason) {
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());

    expect(suggestionRefusal(fn () => suggestTranslation(...$arguments($target))))->toStartWith($reason)
        ->and($provider->received)->toBe([]);
})->with([
    'English' => [fn (string $target): array => ['categories:'.untranslatedCategory()->id.':name', 'en'], 'reference_locale'],
    'a language that is not installed' => [fn (string $target): array => ['categories:'.untranslatedCategory()->id.':name', 'xx'], 'unknown_locale'],
    'no language' => [fn (string $target): array => ['categories:'.untranslatedCategory()->id.':name', null], 'unknown_locale'],
    'a malformed unit' => [fn (string $target): array => ['categories:abc:name', $target], 'unknown_unit'],
    'not a unit at all' => [fn (string $target): array => [['section' => 'categories'], $target], 'unknown_unit'],
    'a field nobody translates' => [fn (string $target): array => ['categories:'.untranslatedCategory()->id.':slug', $target], 'unknown_unit'],
    'a deleted record' => [function (string $target): array {
        $category = untranslatedCategory();
        $category->delete();

        return ["categories:{$category->id}:name", $target];
    }, 'unknown_unit'],
    'an inactive category' => [fn (string $target): array => ['categories:'.Category::factory()->create(['name' => 'Hidden', 'is_active' => false])->id.':name', $target], 'unknown_unit'],
    'an archived rating option' => [fn (string $target): array => [
        'rating_options:'.RatingOption::factory()->for(RatingGroup::factory()->create(['is_active' => true]), 'group')->create(['is_active' => true, 'archived_at' => now()])->id.':label',
        $target,
    ], 'unknown_unit'],
    'blank English' => [fn (string $target): array => ['rating_groups:'.RatingGroup::factory()->create(['description' => '  ', 'is_active' => true])->id.':description', $target], 'nothing_to_translate'],
    'a translation saved meanwhile' => [fn (string $target): array => [
        'categories:'.Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$target => 'Собаки'], 'is_active' => true])->id.':name',
        $target,
    ], 'already_translated'],
]);

it('tells whoever finds a translation saved meanwhile to reload rather than overwriting it', function () {
    [$target] = twoTranslatedLocales();
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$target => 'Собаки'], 'is_active' => true]);

    expect(suggestionRefusal(fn () => suggestTranslation("categories:{$category->id}:name", $target)))
        ->toBe('already_translated: This translation was saved by someone else. Reload the page to review it.')
        ->and($category->fresh()->name_translations)->toBe([$target => 'Собаки']);
});

it('refuses someone who may not manage project settings, without asking the engine', function (Closure $user) {
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $category = untranslatedCategory();

    expect(suggestionRefusal(fn () => suggestTranslation("categories:{$category->id}:name", $target, $user())))
        ->toStartWith('not_allowed')
        ->and($provider->received)->toBe([]);
})->with([
    'a moderator' => fn () => User::factory()->moderator()->create(),
    'a member' => fn () => User::factory()->create(),
]);

// When the engine produces nothing ---------------------------------------------------

it('says safely why the engine produced no suggestion', function (TranslationErrorCode $code, string $message) {
    [$target] = twoTranslatedLocales();
    useScriptedTranslationProvider(failingTranslationProvider($code));
    $category = untranslatedCategory();

    expect(suggestionRefusal(fn () => suggestTranslation("categories:{$category->id}:name", $target)))->toBe("engine_failed: {$message}")
        ->and($category->fresh()->name_translations)->toBeNull();
})->with([
    'authentication' => [TranslationErrorCode::AuthenticationFailed, 'The translation provider rejected the credentials. Machine translation is unavailable until they are fixed.'],
    'rate limit' => [TranslationErrorCode::RateLimited, 'The translation provider is busy. Try again in a moment.'],
    'timeout' => [TranslationErrorCode::ProviderTimeout, 'The translation provider did not answer in time. Try again.'],
    'unavailable' => [TranslationErrorCode::ProviderUnavailable, 'The translation provider is unavailable. Try again.'],
    'refusal' => [TranslationErrorCode::ProviderRefused, 'The translation provider declined to translate this text. Translate it manually.'],
    'invalid response' => [TranslationErrorCode::InvalidProviderResponse, 'The translation provider returned an answer that could not be used. Try again.'],
    'rejected request' => [TranslationErrorCode::ProviderRejectedRequest, 'The translation provider rejected the request. Translate this item manually.'],
]);

it('refuses a suggestion that breaks the field\'s constraints, as the engine judged it', function (string $translation) {
    [$target] = twoTranslatedLocales();
    useScriptedTranslationProvider(answeringTranslationProvider([$translation]));
    $category = Category::factory()->create(['name' => 'Write to {contact_email}', 'is_active' => true]);

    expect(suggestionRefusal(fn () => suggestTranslation("categories:{$category->id}:name", $target)))
        ->toBe('engine_failed: The AI translation did not meet this field’s constraints. Try generating again or translate it manually.');
})->with([
    'too long' => [str_repeat('x', 81).' {contact_email}'],
    'a line break' => ["Напишите\n{contact_email}"],
    'a lost placeholder' => ['Напишите нам'],
    'blank' => ['   '],
]);

it('says machine translation is not configured when there is no API key, and sends nothing', function () {
    [$target] = twoTranslatedLocales();
    configureOpenAiTranslation(['api_key' => null]);
    Http::fake();
    $category = untranslatedCategory();

    expect(suggestionRefusal(fn () => suggestTranslation("categories:{$category->id}:name", $target)))
        ->toBe('engine_failed: Machine translation is not configured.');

    Http::assertNothingSent();
});

it('never repeats what the provider said, a credential or the text', function () {
    // The real OpenAI provider over a faked API that echoes the key and its
    // own words back in a refusal.
    [$target] = twoTranslatedLocales();
    configureOpenAiTranslation();
    Log::spy();
    Http::fake(['*/responses' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: '.TRANSLATION_TEST_API_KEY.' — upstream detail 7f3a'],
    ], 401)]);
    $category = untranslatedCategory('A secret family recipe');

    $refusal = (string) suggestionRefusal(fn () => suggestTranslation("categories:{$category->id}:name", $target));

    expect($refusal)->toBe('engine_failed: The translation provider rejected the credentials. Machine translation is unavailable until they are fixed.')
        ->not->toContain(TRANSLATION_TEST_API_KEY)
        ->not->toContain('upstream detail')
        ->not->toContain('secret family recipe');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $event, array $context): bool => ! str_contains(json_encode($context), 'secret family recipe')
        && ! str_contains(json_encode($context), TRANSLATION_TEST_API_KEY));
});

it('asks once per request: a failure is not retried', function () {
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(failingTranslationProvider(TranslationErrorCode::ProviderUnavailable));
    $category = untranslatedCategory();

    suggestionRefusal(fn () => suggestTranslation("categories:{$category->id}:name", $target));

    expect($provider->received)->toHaveCount(1)
        ->and($provider->received[0])->toBeInstanceOf(TranslationBatchRequest::class);
});

it('has no way to store a suggestion: neither the action nor the page method writes anything', function () {
    $action = phpSourceWithoutComments('app/Actions/Translations/GenerateProjectTranslationSuggestionAction.php');

    $method = new ReflectionMethod(TranslationCenterPage::class, 'suggest');
    $page = implode('', array_slice(file(app_path('Filament/Pages/TranslationCenterPage.php')), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    foreach ([$action, $page] as $source) {
        expect($source)->not->toContain('->write(')
            ->not->toContain('->save(')
            ->not->toContain('->update(')
            ->not->toContain('DB::')
            ->not->toContain('UpdateProjectTranslationAction');
    }
});
