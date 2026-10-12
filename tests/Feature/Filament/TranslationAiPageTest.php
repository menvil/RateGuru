<?php

use App\Filament\Pages\TranslationAiPage;
use App\Livewire\Admin\TranslationAiGenerations;
use App\Models\TranslationAiProviderCall;
use App\Models\User;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\Translations\Generation\ProjectTranslationGenerationItemStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Url;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Translation AI: the read-only technical page over Translation AI telemetry
 * and the configuration — provider health, usage and cost, failures, the
 * recent calls, background generations and the guardrails. These tests read
 * what the page renders, drive its filters the way the browser does, and make
 * sure it shows metadata only and never reaches a provider.
 */
function translationAiScreen(array $query = []): Testable
{
    return Livewire::withQueryParams($query)->test(TranslationAiPage::class);
}

/** An element's text, whitespace collapsed, or null when the page has none. */
function translationAiText(Testable|string $page, string $query): ?string
{
    $node = livewireDom($page)->query($query)->item(0);

    return $node === null ? null : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
}

/** @return list<string> the text of every element a query finds */
function translationAiTexts(Testable|string $page, string $query): array
{
    return array_map(
        fn (DOMNode $node): string => trim((string) preg_replace('/\s+/u', ' ', $node->textContent)),
        iterator_to_array(livewireDom($page)->query($query)),
    );
}

/** @return array<string, string> the usage figures, label => value and note */
function translationAiFigures(Testable $page): array
{
    $xpath = livewireDom($page);
    $figures = [];

    foreach ($xpath->query("//div[contains(@class, 'rg-admin-translation-ai__figure ')] | //div[@class='rg-admin-translation-ai__figure']") as $figure) {
        $figures[trim($xpath->query('dt', $figure)->item(0)->textContent)] = implode(' | ', array_map(
            fn (DOMNode $node): string => trim((string) preg_replace('/\s+/u', ' ', $node->textContent)),
            iterator_to_array($xpath->query('dd', $figure)),
        ));
    }

    return $figures;
}

/** @return array<string, string> Provider health, label => value, with its status first */
function translationAiHealth(Testable $page): array
{
    $xpath = livewireDom($page);
    $rows = ['status' => (string) $xpath->query('//*[@data-health]')->item(0)?->getAttribute('data-health')];

    foreach ($xpath->query("//section[@aria-labelledby='rg-admin-translation-ai-health']//div[contains(@class, 'rg-admin-detail-row')]") as $row) {
        $rows[trim($xpath->query('dt', $row)->item(0)->textContent)] = trim((string) preg_replace('/\s+/u', ' ', $xpath->query('dd', $row)->item(0)->textContent));
    }

    return $rows;
}

/** @return array<string, string> each failure row by its code: what it shows besides the code */
function translationAiFailureRows(Testable $page): array
{
    $xpath = livewireDom($page);
    $rows = [];

    foreach ($xpath->query('//li[@data-code]') as $row) {
        $rows[$row->getAttribute('data-code')] = trim((string) preg_replace('/\s+/u', ' ', $xpath->query(".//div[contains(@class, 'rg-admin-translation-ai__failure-figures')]", $row)->item(0)->textContent));
    }

    return $rows;
}

/** @return list<string> the recent calls as rows of text, in the page's order */
function translationAiCallRows(Testable $page): array
{
    return translationAiTexts($page, "//div[@role='row'][@data-result]");
}

function translationAiCall(string $at, array $attributes = []): TranslationAiProviderCall
{
    return TranslationAiProviderCall::factory()->create(['completed_at' => CarbonImmutable::parse($at, 'UTC'), ...$attributes]);
}

function failedTranslationAiCall(string $at, TranslationErrorCode $code, ?int $httpStatus = null, array $attributes = []): TranslationAiProviderCall
{
    return TranslationAiProviderCall::factory()->state(['item_count' => $attributes['item_count'] ?? 1])->failedWith($code, $httpStatus)
        ->create(['completed_at' => CarbonImmutable::parse($at, 'UTC'), ...$attributes]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    config(['translation.providers.openai.api_key' => 'test-key', 'translation.providers.openai.model' => 'gpt-6-luna']);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

// =============================================================================
// Access and place
// =============================================================================

it('opens to an administrator who may manage project settings, as an Admin v2 screen', function () {
    $html = $this->get('/admin/translation-ai')->assertOk()->getContent();

    expect($html)->toContain('rg-admin-screen')
        ->toContain('<h1 id="rg-admin-translation-ai-title" class="rg-admin-page-header__title">Translation AI</h1>')
        ->not->toContain('fi-header-heading')
        ->and(TranslationAiPage::getNavigationGroup())->toBe('Localization')
        ->and(TranslationAiPage::getNavigationSort())->toBeGreaterThan(20);
});

it('stays closed to anyone who may not manage project settings', function (Closure $user) {
    $this->actingAs($user())->get('/admin/translation-ai')->assertForbidden();
})->with([
    'a moderator' => [fn () => User::factory()->moderator()->create()],
    'a member' => [fn () => User::factory()->create()],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create()],
]);

it('sends a guest to the panel login', function () {
    auth()->logout();

    $this->get('/admin/translation-ai')->assertRedirect(route('filament.admin.auth.login'));
});

it('refuses its background generations to anyone who may not manage project settings, on every request', function () {
    Livewire::actingAs(User::factory()->moderator()->create())->test(TranslationAiGenerations::class)->assertForbidden();
});

it('links to Translation Center, where translations are edited, and edits nothing itself', function () {
    $page = translationAiScreen();

    expect(livewireFragment($page, "//p[contains(@class, 'rg-admin-page-header__description')]"))->toContain('href="'.e(url('/admin/translation-center')).'"')
        ->and(livewireDom($page)->query('//input[not(@type="search") and not(contains(@id, "-search"))] | //textarea | //form')->length)->toBe(0);
});

// =============================================================================
// Period and the model filter
// =============================================================================

it('keeps the period, the model, the call filter and the page in the URL', function (string $property, ?string $as) {
    $attribute = (new ReflectionProperty(TranslationAiPage::class, $property))->getAttributes(Url::class)[0] ?? null;

    expect($attribute)->not->toBeNull()
        ->and($attribute->getArguments()['as'] ?? null)->toBe($as);
})->with([
    ['period', null],
    ['model', null],
    ['calls', null],
    ['callsPage', 'page'],
]);

it('shows the last 7 days by default, and 24 hours or 30 days when asked', function (?string $period, string $label, int $bars, string $title) {
    translationAiCall('2026-10-12 09:00:00');

    $page = translationAiScreen(array_filter(['period' => $period]));

    expect($page->get('period'))->toBe(in_array($period, ['24h', '30d'], true) ? $period : '7d')
        ->and(translationAiText($page, "//section[@aria-labelledby='rg-admin-translation-ai-usage']//p"))->toStartWith($label)
        ->and(livewireDom($page)->query("//li[contains(@class, 'rg-admin-translation-ai__bar')]")->length)->toBe($bars)
        ->and(translationAiText($page, "//span[@id='rg-admin-translation-ai-chart-title']"))->toBe($title)
        ->and(translationAiText($page, "//*[@role='radio'][@aria-checked='true']"))->toBe(['24h' => '24 h', '30d' => '30 days'][$period] ?? '7 days');
})->with([
    'the default' => [null, 'Last 7 days', 7, 'Calls per day'],
    '24 hours' => ['24h', 'Last 24 hours', 24, 'Calls per hour'],
    '30 days' => ['30d', 'Last 30 days', 30, 'Calls per day'],
    'a period it does not offer' => ['90d', 'Last 7 days', 7, 'Calls per day'],
]);

it('counts the calls of the period only, and redraws when the period changes', function () {
    translationAiCall('2026-10-12 09:00:00');
    translationAiCall('2026-10-09 09:00:00');
    translationAiCall('2026-09-20 09:00:00');

    $page = translationAiScreen();

    expect(translationAiFigures($page)['Provider calls'])->toStartWith('2 |');

    $page->set('period', '24h');
    expect(translationAiFigures($page)['Provider calls'])->toStartWith('1 |');

    $page->set('period', '30d');
    expect(translationAiFigures($page)['Provider calls'])->toStartWith('3 |');
});

it('offers the models that have history, and the configured model before it has any — never a demonstration list', function () {
    translationAiCall('2026-10-12 09:00:00', ['model' => 'gpt-5-luna-2026-05-01']);

    $options = translationAiTexts(translationAiScreen(), "//div[@role='option']//span[contains(@class, 'rg-admin-combobox__option-label')]");
    $html = translationAiScreen()->html();

    expect($options)->toBe(['All models', 'gpt-6-luna', 'gpt-5-luna-2026-05-01'])
        ->and(translationAiText(translationAiScreen(), "//div[@role='option'][@data-value='gpt-6-luna']"))->toContain('Configured now · no calls recorded')
        ->and($html)->not->toContain('gpt-4.1-mini')->not->toContain('gpt-4o-mini')->not->toContain('gpt-4.1<');
});

it('marks the configured model as reported by its provider, a dated snapshot included, and adds no second entry for it', function () {
    translationAiCall('2026-10-12 09:00:00', ['model' => 'gpt-6-luna-2026-09-01']);
    translationAiCall('2026-09-01 09:00:00', ['model' => 'gpt-5-luna']);

    $page = translationAiScreen();

    expect(translationAiTexts($page, "//div[@role='option']//span[contains(@class, 'rg-admin-combobox__option-label')]"))->toBe(['All models', 'gpt-6-luna-2026-09-01', 'gpt-5-luna'])
        ->and(translationAiText($page, "//div[@role='option'][@data-value='gpt-6-luna-2026-09-01']"))->toContain('Configured now')->toContain('1 call')
        ->and(translationAiText($page, "//div[@role='option'][@data-value='gpt-5-luna']"))->toContain('Last call 1 Sep 2026')->toContain('0 calls');
});

it('narrows usage, failures and calls to one model, and back', function () {
    translationAiCall('2026-10-12 09:00:00', ['model' => 'gpt-6-luna-2026-09-01']);
    translationAiCall('2026-10-11 09:00:00', ['model' => 'gpt-5-luna']);
    failedTranslationAiCall('2026-10-11 10:00:00', TranslationErrorCode::ProviderTimeout, attributes: ['model' => 'gpt-5-luna']);

    $page = translationAiScreen()->set('model', 'gpt-5-luna');

    expect(translationAiFigures($page)['Provider calls'])->toStartWith('2 |')
        ->and(translationAiCallRows($page))->toHaveCount(2)
        ->and(translationAiFailureRows($page)['provider_timeout'])->toContain('1 failed call')
        ->and(translationAiText($page, '//*[@id="rg-admin-translation-ai-model-trigger"]'))->toContain('gpt-5-luna');

    $page->set('model', '');

    expect(translationAiFigures($page)['Provider calls'])->toStartWith('3 |')
        ->and($page->get('model'))->toBeNull();
});

it('reads a model the page does not offer as every model', function () {
    translationAiCall('2026-10-12 09:00:00');

    $page = translationAiScreen(['model' => 'gpt-4.1-mini']);

    expect(translationAiFigures($page)['Provider calls'])->toStartWith('1 |')
        ->and(translationAiText($page, '//*[@id="rg-admin-translation-ai-model-trigger"]'))->toContain('All models');
});

// =============================================================================
// Usage and cost
// =============================================================================

it('shows the period’s provider calls, translated items, tokens and known estimated cost', function () {
    translationAiCall('2026-10-11 09:00:00', ['item_count' => 10, 'succeeded_item_count' => 9, 'failed_item_count' => 1, 'item_failures' => ['constraint_violation' => 1]]);
    failedTranslationAiCall('2026-10-10 12:00:00', TranslationErrorCode::ProviderTimeout, 504, ['item_count' => 10]);
    translationAiCall('2026-10-12 09:30:00', [
        'consumer' => 'interactive_ai', 'item_count' => 1, 'succeeded_item_count' => 1,
        'input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120, 'estimated_cost_usd' => '0.000020000000',
    ]);
    translationAiCall('2026-10-05 23:59:59');

    expect(translationAiFigures(translationAiScreen()))->toBe([
        'Provider calls' => '3 | 1 failed',
        // Items translated, not items sent: the timeout's ten and one rejected item are not.
        'Translated items' => '10 | of 21 sent',
        'Tokens in · out' => '1.1K · 220 | 1 call without reported usage',
        'Estimated cost' => '< $0.01 | 1 call with unknown cost',
    ]);
});

it('never turns an unknown cost into a free one', function (array $costs, string $shown) {
    foreach ($costs as $minute => $cost) {
        translationAiCall("2026-10-12 09:0{$minute}:00", ['estimated_cost_usd' => $cost]);
    }

    expect(translationAiFigures(translationAiScreen())['Estimated cost'])->toBe($shown);
})->with([
    'every cost known' => [['1.234500000000', '0.010000000000'], '$1.24'],
    'part of it unknown' => [['1.234500000000', null, null], '$1.23 | 2 calls with unknown cost'],
    'all of it unknown' => [[null, null], 'Unknown | 2 calls with unknown cost'],
    'no calls at all' => [[], '$0.00'],
]);

it('charts calls per hour over 24 hours, failed calls apart from the rest', function () {
    translationAiCall('2026-10-12 09:10:00');
    translationAiCall('2026-10-12 09:20:00');
    failedTranslationAiCall('2026-10-12 09:30:00', TranslationErrorCode::RateLimited, 429);
    translationAiCall('2026-10-11 10:30:00');

    $titles = translationAiTexts(translationAiScreen(['period' => '24h']), "//li[contains(@class, 'rg-admin-translation-ai__bar')]/span[contains(@class, 'rg-admin-sr-only')]");

    expect($titles)->toHaveCount(24)
        ->and($titles[0])->toBe('11 Oct 11:00 · 0 calls')
        ->and($titles[22])->toBe('12 Oct 09:00 · 3 calls · 1 failed')
        ->and($titles[23])->toBe('12 Oct 10:00 · 0 calls')
        ->and(translationAiTexts(translationAiScreen(['period' => '24h']), "//div[contains(@class, 'rg-admin-translation-ai__ticks')]/span"))->toBe(['11:00', '17:00', '23:00', '05:00', '10:00']);
});

it('charts calls per day over 7 and 30 days', function () {
    translationAiCall('2026-10-06 00:00:00');
    failedTranslationAiCall('2026-10-12 08:00:00', TranslationErrorCode::ProviderUnavailable, 503);
    translationAiCall('2026-09-13 12:00:00');

    $week = translationAiTexts(translationAiScreen(), "//li[contains(@class, 'rg-admin-translation-ai__bar')]/span[contains(@class, 'rg-admin-sr-only')]");
    $month = translationAiTexts(translationAiScreen(['period' => '30d']), "//li[contains(@class, 'rg-admin-translation-ai__bar')]/span[contains(@class, 'rg-admin-sr-only')]");

    expect($week)->toBe(['6 Oct · 1 call', '7 Oct · 0 calls', '8 Oct · 0 calls', '9 Oct · 0 calls', '10 Oct · 0 calls', '11 Oct · 0 calls', '12 Oct · 1 call · 1 failed'])
        ->and($month)->toHaveCount(30)
        ->and($month[0])->toBe('13 Sep · 1 call');
});

it('draws no chart and no figures it does not have when the period has no calls', function () {
    $page = translationAiScreen();

    expect(livewireDom($page)->query("//figure[contains(@class, 'rg-admin-translation-ai__chart')]")->length)->toBe(0)
        ->and(translationAiText($page, "//section[@aria-labelledby='rg-admin-translation-ai-usage']//*[contains(@class, 'rg-admin-empty-state')]"))
        ->toBe('Nothing to chart yet No provider calls in the last 7 days.')
        ->and(translationAiFigures($page))->toBe(['Provider calls' => '0', 'Translated items' => '0', 'Tokens in · out' => '0 · 0', 'Estimated cost' => '$0.00']);
});

// =============================================================================
// Failures and issues
// =============================================================================

it('counts provider failures by call and item failures by item, never adding them up', function () {
    failedTranslationAiCall('2026-10-11 12:00:00', TranslationErrorCode::ProviderTimeout, 504, ['item_count' => 10]);
    translationAiCall('2026-10-11 13:00:00', ['item_count' => 10, 'succeeded_item_count' => 7, 'failed_item_count' => 3, 'item_failures' => ['constraint_violation' => 2, 'invalid_provider_response' => 1]]);

    $rows = translationAiFailureRows(translationAiScreen());

    expect($rows['provider_timeout'])->toBe('1 failed call 10 failed items Isolated')
        // The provider answered: a check refused its translations. No pattern, no outage.
        ->and($rows['constraint_violation'])->toBe('2 failed items Provider answered')
        ->and($rows['invalid_provider_response'])->toBe('1 failed item Provider answered')
        ->and($rows['rate_limited'])->toBe('0')
        ->and($rows['authentication_failed'])->toBe('0')
        ->and($rows['provider_unavailable'])->toBe('0')
        ->and(array_keys($rows))->toBe(['provider_timeout', 'constraint_violation', 'invalid_provider_response', 'rate_limited', 'authentication_failed', 'provider_unavailable']);
});

it('lists any other failure code once it happens', function () {
    failedTranslationAiCall('2026-10-12 08:00:00', TranslationErrorCode::ProviderRefused, 200);

    expect(translationAiFailureRows(translationAiScreen()))->toHaveKey('provider_refused', '1 failed call 1 failed item Isolated')
        ->and(translationAiText(translationAiScreen(), "//li[@data-code='provider_refused']"))->toContain('The model declined to translate the content');
});

it('says there are no issues in a period without any, and invents no past incident', function () {
    translationAiCall('2026-10-12 08:00:00');

    $page = translationAiScreen();

    expect(translationAiText($page, "//section[@aria-labelledby='rg-admin-translation-ai-failures']//*[contains(@class, 'rg-admin-notice')]"))->toBe('No issues in this period.')
        ->and(translationAiText($page, "//section[@aria-labelledby='rg-admin-translation-ai-failures']//header"))->toContain('No systemic issue')
        ->and(array_values(array_unique(translationAiFailureRows($page))))->toBe(['0']);
});

it('tells a burst that is over from an ongoing one, and only an ongoing one is systemic', function () {
    foreach (range(0, 5) as $step) {
        failedTranslationAiCall('2026-10-11 18:20:'.str_pad((string) ($step * 9), 2, '0', STR_PAD_LEFT), TranslationErrorCode::AuthenticationFailed, 401);
    }

    $over = translationAiScreen();

    expect(translationAiFailureRows($over)['authentication_failed'])->toBe('6 failed calls 6 failed items Burst')
        ->and(translationAiText($over, "//section[@aria-labelledby='rg-admin-translation-ai-failures']//*[contains(@class, 'rg-admin-notice')]"))
        ->toBe('No systemic issue now. The latest burst: 6 authentication_failed failures in 1 min, yesterday 18:20 UTC; none since.');

    foreach (range(0, 4) as $minute) {
        failedTranslationAiCall('2026-10-12 09:5'.$minute.':00', TranslationErrorCode::ProviderTimeout, null, ['latency_ms' => 45000]);
    }

    $ongoing = translationAiScreen();

    expect(translationAiFailureRows($ongoing)['provider_timeout'])->toBe('5 failed calls 5 failed items Ongoing')
        ->and(translationAiText($ongoing, "//section[@aria-labelledby='rg-admin-translation-ai-failures']//*[contains(@class, 'rg-admin-notice')]"))
        ->toBe('Systemic issue: provider_timeout since 09:50 UTC and still failing — 5 failed calls in this series.')
        ->and(translationAiText($ongoing, "//section[@aria-labelledby='rg-admin-translation-ai-failures']//header"))->toContain('Systemic issue');
});

// =============================================================================
// Provider health
// =============================================================================

it('says Not configured when a translation would be refused before anything is sent', function (array $config, string $reason) {
    config($config);

    $health = translationAiHealth(translationAiScreen());

    expect($health['status'])->toBe('Not configured')
        ->and(translationAiText(translationAiScreen(), "//section[@aria-labelledby='rg-admin-translation-ai-health']//p[contains(@class, 'rg-admin-translation-ai__section-note')]"))->toBe($reason);
})->with([
    'no API key' => [['translation.providers.openai.api_key' => null], 'The API key is missing. Translations are refused before anything is sent.'],
    'no provider' => [['translation.default' => ''], 'No translation provider is configured. Translations are refused before anything is sent.'],
    'an unknown provider' => [['translation.default' => 'nowhere'], 'The configured provider is not registered. Translations are refused before anything is sent.'],
]);

it('says Configured, not Healthy, for a key with no recent success to show it works', function () {
    translationAiCall('2026-10-10 09:00:00');

    $health = translationAiHealth(translationAiScreen());

    expect($health['status'])->toBe('Configured')
        ->and($health['API key'])->toBe('Configured')
        ->and($health['Last success'])->toBe('10 Oct 09:00 UTC · 2 days ago');
});

it('says Healthy after a recent success on the configured model, a dated snapshot of it as its provider reported', function () {
    translationAiCall('2026-10-12 09:15:00', ['model' => 'gpt-6-luna-2026-09-01', 'latency_ms' => 1200]);
    translationAiCall('2026-10-12 09:30:00', ['model' => 'gpt-6-luna-2026-09-01', 'latency_ms' => 2400]);
    failedTranslationAiCall('2026-10-12 09:40:00', TranslationErrorCode::RateLimited, 429, ['model' => 'gpt-6-luna-2026-09-01', 'latency_ms' => 300]);

    expect(translationAiHealth(translationAiScreen()))->toBe([
        'status' => 'Healthy',
        'Provider' => 'openai',
        'Model' => 'gpt-6-luna',
        'API key' => 'Configured',
        'Last success' => 'Today 09:30 UTC · 30 min ago',
        'Average response' => '1.3 s',
        'p95 latency' => '2.4 s',
        'Success rate' => '66.7% · 2 of 3 calls',
        'Retry policy' => 'None',
        'Fallback provider' => 'None',
    ]);
});

it('says Degraded while failures of the configured model are ongoing', function () {
    translationAiCall('2026-10-12 09:00:00');
    foreach (range(0, 4) as $minute) {
        failedTranslationAiCall('2026-10-12 09:5'.$minute.':00', TranslationErrorCode::ProviderUnavailable, 503);
    }

    expect(translationAiHealth(translationAiScreen())['status'])->toBe('Degraded')
        ->and(translationAiText(translationAiScreen(), "//section[@aria-labelledby='rg-admin-translation-ai-health']//p[contains(@class, 'rg-admin-translation-ai__section-note')]"))
        ->toBe('provider_unavailable is failing repeatedly: 5 failed calls since 09:50 UTC.');
});

it('keeps the health of the configuration whatever older model the history is filtered to', function () {
    translationAiCall('2026-10-12 09:00:00', ['model' => 'gpt-6-luna-2026-09-01']);
    foreach (range(0, 5) as $minute) {
        failedTranslationAiCall('2026-10-12 09:5'.$minute.':00', TranslationErrorCode::AuthenticationFailed, 401, ['model' => 'gpt-5-luna']);
    }

    $everything = translationAiScreen();
    $old = translationAiScreen(['model' => 'gpt-5-luna']);

    expect(translationAiHealth($old))->toBe(translationAiHealth($everything))
        ->and(translationAiHealth($old)['status'])->toBe('Healthy')
        ->and(translationAiHealth($old)['Success rate'])->toBe('100.0% · 1 of 1 call')
        ->and(translationAiFailureRows($old)['authentication_failed'])->toContain('Ongoing');
});

// =============================================================================
// Recent provider calls
// =============================================================================

it('lists the recent calls newest first, with their metadata and nothing else', function () {
    translationAiCall('2026-10-11 09:00:00', ['target_locale' => 'sr', 'provider_target_locale' => 'sr-Cyrl', 'item_count' => 10, 'succeeded_item_count' => 9, 'failed_item_count' => 1, 'item_failures' => ['constraint_violation' => 1], 'latency_ms' => 6200]);
    failedTranslationAiCall('2026-10-11 12:00:00', TranslationErrorCode::ProviderTimeout, 504, ['item_count' => 10, 'latency_ms' => 45000]);
    translationAiCall('2026-10-12 09:30:07', [
        'consumer' => 'interactive_ai', 'target_locale' => 'de', 'provider_target_locale' => 'de', 'item_count' => 1, 'succeeded_item_count' => 1,
        'input_tokens' => 620, 'output_tokens' => 38, 'total_tokens' => 658, 'estimated_cost_usd' => '0.000081000000', 'latency_ms' => 1400,
    ]);
    translationAiCall('2026-10-12 08:00:00', ['model' => 'gpt-6-luna-mini', 'estimated_cost_usd' => null, 'input_usd_per_million_tokens' => null, 'output_usd_per_million_tokens' => null]);

    expect(translationAiCallRows(translationAiScreen()))->toBe([
        '09:30:07 Today Interactive AI One field gpt-6-luna-2026-09-01 openai 🇩🇪 de 1 620 / 38 $0.000081 1.4 s Success',
        '08:00:00 Today Generate missing Background gpt-6-luna-mini openai 🇩🇪 de 10 1,000 / 200 — (unknown cost) 1.2 s Success',
        '12:00:00 Yesterday Generate missing Background gpt-6-luna-2026-09-01 openai 🇩🇪 de 10 — / — (usage not reported) — (unknown cost) 45.0 s provider_timeout HTTP 504 · 10 items not translated',
        '09:00:00 Yesterday Generate missing Background gpt-6-luna-2026-09-01 openai 🇷🇸 sr as sr-Cyrl 10 1,000 / 200 $0.0002 6.2 s 9 of 10 translated constraint_violation × 1',
    ]);
});

it('tells a call that failed from one whose provider answered but some items did not pass', function () {
    translationAiCall('2026-10-11 09:00:00', ['item_count' => 10, 'succeeded_item_count' => 9, 'failed_item_count' => 1, 'item_failures' => ['constraint_violation' => 1]]);
    failedTranslationAiCall('2026-10-11 12:00:00', TranslationErrorCode::ProviderTimeout, null, ['item_count' => 10]);
    translationAiCall('2026-10-11 13:00:00');

    expect(array_map(fn (DOMElement $row): string => $row->getAttribute('data-result'), iterator_to_array(livewireDom(translationAiScreen())->query("//div[@role='row'][@data-result]"))))
        ->toBe(['success', 'failed', 'partial']);
});

it('shows only the calls with issues when asked, and says so when there are none', function () {
    translationAiCall('2026-10-11 09:00:00', ['item_count' => 10, 'succeeded_item_count' => 9, 'failed_item_count' => 1, 'item_failures' => ['constraint_violation' => 1]]);
    failedTranslationAiCall('2026-10-11 12:00:00', TranslationErrorCode::ProviderTimeout);
    translationAiCall('2026-10-11 13:00:00');

    $page = translationAiScreen();

    expect(translationAiTexts($page, "//*[@aria-label='Show calls']//*[@role='radio']"))->toBe(['All · 3', 'With issues · 2'])
        ->and(translationAiCallRows($page->set('calls', 'issues')))->toHaveCount(2);

    TranslationAiProviderCall::query()->where('status', 'failed')->orWhere('failed_item_count', '>', 0)->delete();

    expect(translationAiText(translationAiScreen(['calls' => 'issues']), "//section[@aria-labelledby='rg-admin-translation-ai-calls']//*[contains(@class, 'rg-admin-empty-state')]"))
        ->toBe('No calls with issues in the last 7 days Every call succeeded with every item translated.');
});

it('pages through the calls on the server, 25 at a time, back to the first page when a filter changes', function () {
    foreach (range(1, 30) as $minute) {
        translationAiCall(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC')->addMinutes($minute)->toDateTimeString());
    }
    failedTranslationAiCall('2026-10-12 07:00:00', TranslationErrorCode::RateLimited);

    $page = translationAiScreen();

    expect(translationAiCallRows($page))->toHaveCount(25)
        ->and(translationAiText($page, "//nav[contains(@class, 'rg-admin-pagination')]"))->toStartWith('1–25 of 31 calls · newest first');

    $page->call('goToCallsPage', 2);

    expect(translationAiCallRows($page))->toHaveCount(6)
        ->and($page->get('callsPage'))->toBe(2)
        ->and(translationAiText($page, "//nav[contains(@class, 'rg-admin-pagination')]"))->toStartWith('26–31 of 31 calls')
        ->and(livewireFragment($page, "//nav[contains(@class, 'rg-admin-pagination')]//*[@aria-current='page']"))->toContain('>2<');

    $page->set('calls', 'issues');

    expect($page->get('callsPage'))->toBe(1)
        ->and(translationAiCallRows($page))->toHaveCount(1);
});

it('shows the last page for a page past the end, and the first for one that is not a page', function (mixed $requested, string $range) {
    foreach (range(1, 30) as $minute) {
        translationAiCall(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC')->addMinutes($minute)->toDateTimeString());
    }

    expect(translationAiText(translationAiScreen(['page' => $requested]), "//nav[contains(@class, 'rg-admin-pagination')]"))->toStartWith($range);
})->with([
    'past the end' => [9, '26–30 of 30 calls'],
    'not a number' => ['two', '1–25 of 30 calls'],
    'below one' => [-3, '1–25 of 30 calls'],
]);

it('reads one page of calls however long the history is, never every row', function () {
    TranslationAiProviderCall::factory()->count(120)->create(['completed_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC')]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $page = translationAiScreen();
    $rowReads = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'translation_ai_provider_calls') && (str_contains($sql, 'select *') || str_contains($sql, '.*'))));

    expect(translationAiCallRows($page))->toHaveCount(25)
        ->and($rowReads)->not->toBeEmpty()
        ->and(array_filter($rowReads, fn (string $sql): bool => ! str_contains($sql, 'limit')))->toBe([]);
});

it('says when there is no history at all, in every block', function () {
    $page = translationAiScreen();

    expect(translationAiText($page, "//section[@aria-labelledby='rg-admin-translation-ai-calls']//*[contains(@class, 'rg-admin-empty-state')]"))->toStartWith('No provider calls in the last 7 days')
        ->and(translationAiText($page, "//section[@aria-labelledby='rg-admin-translation-ai-failures']//*[contains(@class, 'rg-admin-notice')]"))->toBe('No provider calls in the last 7 days.')
        ->and(translationAiHealth($page)['Last success'])->toBe('Never')
        ->and(translationAiHealth($page)['Success rate'])->toBe('No calls')
        ->and(livewireDom($page)->query("//nav[contains(@class, 'rg-admin-pagination')]")->length)->toBe(0);
});

// =============================================================================
// Background generations
// =============================================================================

it('shows the generation of each language now and the viewer’s own, as counts and times only', function () {
    $store = app(ProjectTranslationGenerationStore::class);
    $other = User::factory()->admin()->create();
    $now = CarbonImmutable::now('UTC');
    $running = $store->create($other->id, 'de', translationGenerationPlan('de', 2, 3), $now->subMinutes(2));
    $store->claim($running['meta']['id'], 'c1', $now->subMinute());
    $store->complete($running['meta']['id'], 'c1', [
        'categories:11:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Kategorie eins', 'provider' => 'openai', 'model' => 'gpt-6-luna'],
        'categories:12:name' => ['status' => ProjectTranslationGenerationItemStatus::Failed, 'issue' => 'constraint_violation', 'message' => 'Too long.'],
        'categories:13:name' => ['status' => ProjectTranslationGenerationItemStatus::Skipped, 'issue' => 'source_changed', 'message' => 'English changed.'],
    ], $now);
    $mine = $store->create($this->admin->id, 'ru', translationGenerationPlan('ru', 1, 2), $now->subHour());
    $store->claim($mine['meta']['id'], 'c1', $now->subHour());
    $store->complete($mine['meta']['id'], 'c1', [
        'categories:11:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Категория один', 'provider' => 'openai', 'model' => 'gpt-6-luna'],
        'categories:12:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Категория два', 'provider' => 'openai', 'model' => 'gpt-6-luna'],
    ], $now->subMinutes(59));

    $block = Livewire::test(TranslationAiGenerations::class);
    $html = $block->html();

    expect(translationAiTexts($block, '//li[@data-locale]'))->toBe([
        '🇩🇪 German — Deutsch de · Started by another administrator · 12 Oct 09:58 UTC Running 3 of 6 processed Ready 1 Failed 1 Skipped 1 Saved 0 Discarded 0 Results kept until 14 Oct 09:58 UTC',
        '🇷🇺 Russian — Русский ru · Started by you · 12 Oct 09:00 UTC Completed 2 of 2 processed Ready 2 Failed 0 Skipped 0 Saved 0 Discarded 0 Results kept until 14 Oct 09:00 UTC Review in Translation Center',
    ])
        ->and($html)->toContain('wire:poll.5s.visible')
        ->not->toContain('Kategorie eins')->not->toContain('Категория')->not->toContain('Category 1.1')
        ->not->toContain('categories:11:name')->not->toContain('Too long.')->not->toContain($running['meta']['id']);
});

it('stops reading the store again once nothing is running', function () {
    $store = app(ProjectTranslationGenerationStore::class);
    $done = $store->create($this->admin->id, 'de', translationGenerationPlan('de', 1, 1), CarbonImmutable::now('UTC'));
    $store->claim($done['meta']['id'], 'c1', CarbonImmutable::now('UTC'));
    $store->complete($done['meta']['id'], 'c1', ['categories:11:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Eins']], CarbonImmutable::now('UTC'));

    $block = Livewire::test(TranslationAiGenerations::class);

    expect(translationAiTexts($block, '//li[@data-locale]'))->toHaveCount(1)
        ->and($block->html())->not->toContain('wire:poll');

    expect(Livewire::actingAs(User::factory()->admin()->create())->test(TranslationAiGenerations::class)->html())
        ->toContain('No background generations')
        ->not->toContain('wire:poll');
});

it('keeps the page working when the temporary store cannot be reached, saying so in that block only', function () {
    config(['translation.bulk.cache_store' => 'unreachable', 'cache.stores.unreachable' => ['driver' => 'redis', 'connection' => 'nowhere']]);
    translationAiCall('2026-10-12 09:00:00');

    $html = $this->get('/admin/translation-ai')->assertOk()->getContent();

    expect($html)->toContain('Temporarily unavailable. The store Generate missing keeps its work in cannot be reached right now')
        ->toContain('Provider health')
        ->toContain('Recent provider calls')
        ->and(translationAiFigures(translationAiScreen())['Provider calls'])->toStartWith('1 |');
});

// =============================================================================
// Safety
// =============================================================================

it('puts no text, prompt, credential or response into the page or its client state', function () {
    config(['translation.providers.openai.api_key' => 'sk-test-never-shown-4242']);
    translationAiCall('2026-10-12 09:00:00', ['external_request_id' => 'resp_never_shown']);
    $store = app(ProjectTranslationGenerationStore::class);
    $store->create($this->admin->id, 'de', translationGenerationPlan('de', 1, 2), CarbonImmutable::now('UTC'));

    $html = $this->get('/admin/translation-ai')->assertOk()->getContent();
    $state = translationAiScreen()->snapshot;

    foreach ([$html, json_encode($state, JSON_UNESCAPED_UNICODE)] as $rendered) {
        expect($rendered)->not->toContain('sk-test-never-shown-4242')
            ->not->toContain('resp_never_shown')
            ->not->toContain('Category 1.1')
            ->not->toContain('categories:11:name')
            ->not->toContain('instructions')
            ->not->toContain('source_text');
    }
});

it('never asks a provider anything, to open, filter or refresh', function () {
    Http::preventStrayRequests();
    Http::fake();
    translationAiCall('2026-10-12 09:00:00');

    $this->get('/admin/translation-ai')->assertOk();
    translationAiScreen()->set('period', '24h')->set('model', 'gpt-6-luna-2026-09-01')->set('calls', 'issues')->call('refreshDiagnostics');
    Livewire::test(TranslationAiGenerations::class)->dispatch('translation-ai-refresh');

    Http::assertNothingSent();
});

it('reads everything again on Refresh, and says so', function () {
    translationAiCall('2026-10-12 09:00:00');
    $page = translationAiScreen();

    translationAiCall('2026-10-12 09:30:00');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-12 10:05:00', 'UTC'));

    $page->call('refreshDiagnostics')
        ->assertDispatched('rg-admin-toast', message: 'Translation AI refreshed', tone: 'info')
        ->assertDispatched('translation-ai-refresh');

    expect(translationAiFigures($page)['Provider calls'])->toStartWith('2 |')
        ->and(translationAiText($page, "//span[contains(@class, 'rg-admin-translation-ai__updated')]"))->toBe('Updated 10:05 UTC');
});

// =============================================================================
// Configuration and guardrails
// =============================================================================

it('shows the guardrails translation runs with, as configured, and says what it cannot know or is not built', function () {
    config(['translation.providers.openai.timeout' => 30, 'queue.default' => 'redis', 'queue.connections.redis.retry_after' => 90]);

    $settings = [];
    $xpath = livewireDom(translationAiScreen());

    foreach ($xpath->query("//div[contains(@class, 'rg-admin-translation-ai__setting')]") as $setting) {
        $settings[trim($xpath->query('dt', $setting)->item(0)->textContent)] = trim($xpath->query('dd', $setting)->item(0)->textContent);
    }

    expect($settings)->toBe([
        'Provider' => 'openai',
        'Model' => 'gpt-6-luna',
        'Pricing' => '$0.10 in · $0.50 out per 1M tokens',
        'Provider timeout' => '30 s · connect 5 s',
        'Translation job timeout' => '75 s',
        'Queue' => 'redis · default',
        'Queue retry_after' => '90 s',
        'Queue worker timeout' => 'Not available',
        'Temporary results' => 'array store · array',
        'Batch TTL' => '48 h',
        'Retry policy' => 'None · one attempt',
        'Fallback provider' => 'None',
        'Hourly limit' => 'Not configured',
        'Budget' => 'Not configured',
        'Telemetry retention' => '90 days',
    ]);
});

it('says a model without rates is not priced', function () {
    config(['translation.providers.openai.model' => 'gpt-7']);

    expect(translationAiText(translationAiScreen(), "//div[contains(@class, 'rg-admin-translation-ai__setting')][dt='Pricing']/dd"))->toBe('Not configured');
});
