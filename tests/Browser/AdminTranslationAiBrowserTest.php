<?php

use App\Models\TranslationAiProviderCall;
use App\Models\User;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\Translations\Generation\ProjectTranslationGenerationItemStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use Carbon\CarbonImmutable;

/*
 * Translation AI in a real browser: the Admin v2 layout at each width, and the
 * period, the model filter, the call filter, the pages and Refresh, each a
 * round trip to the server that the URL keeps — measured from the page the
 * browser built.
 */

/**
 * A week of Translation AI telemetry up to $now, as an administrator would
 * find it: interactive suggestions and Generate missing chunks every day on
 * the current model, one chunk with items its checks refused, an isolated
 * timeout, a burst of rejected credentials yesterday, a model without rates
 * and an older model last used three weeks ago.
 */
function seedTranslationAiWeek(CarbonImmutable $now): void
{
    $call = fn (CarbonImmutable $at, array $attributes = []) => TranslationAiProviderCall::factory()->create(['completed_at' => $at, ...$attributes]);
    $languages = array_slice(translatedLocales(), 0, 3);
    $interactive = ['consumer' => 'interactive_ai', 'item_count' => 1, 'succeeded_item_count' => 1, 'input_tokens' => 620, 'output_tokens' => 38, 'total_tokens' => 658, 'estimated_cost_usd' => '0.000081000000', 'latency_ms' => 1400];
    $chunk = ['consumer' => 'generate_missing', 'item_count' => 20, 'succeeded_item_count' => 20, 'input_tokens' => 9000, 'output_tokens' => 1500, 'total_tokens' => 10500, 'estimated_cost_usd' => '0.001650000000', 'latency_ms' => 6200];

    foreach (range(0, 6) as $daysAgo) {
        $day = $now->startOfDay()->subDays($daysAgo);

        foreach ([8, 9, 11, 14, 16] as $index => $hour) {
            $at = $day->setTime($hour, 10 + $index * 7);

            if ($at->lessThan($now)) {
                $call($at, [...$interactive, 'target_locale' => $languages[$index % count($languages)], 'provider_target_locale' => $languages[$index % count($languages)], 'latency_ms' => 900 + $index * 230]);
            }
        }

        if ($day->setTime(7, 30)->lessThan($now)) {
            $call($day->setTime(7, 30), [...$chunk, 'target_locale' => 'sr', 'provider_target_locale' => 'sr-Cyrl']);
        }
    }

    // Two items of one chunk broke their length limit.
    $call($now->subDays(2)->setTime(12, 5), [...$chunk, 'succeeded_item_count' => 18, 'failed_item_count' => 2, 'item_failures' => ['constraint_violation' => 2]]);
    // One chunk timed out.
    TranslationAiProviderCall::factory()->failedWith(TranslationErrorCode::ProviderTimeout, 504)->create(['completed_at' => $now->subDays(3)->setTime(14, 0), 'consumer' => 'generate_missing', 'latency_ms' => 45000, 'item_count' => 10]);
    // A revoked key, retried by hand six times in two minutes yesterday.
    foreach (range(0, 5) as $attempt) {
        TranslationAiProviderCall::factory()->state(['item_count' => 1])->failedWith(TranslationErrorCode::AuthenticationFailed, 401)->create(['completed_at' => $now->subDay()->setTime(18, 20, $attempt * 20), 'consumer' => 'interactive_ai', 'latency_ms' => 310]);
    }
    // A model without configured rates: its cost is unknown, never $0.
    $call($now->subHours(3), [...$interactive, 'model' => 'gpt-6-luna-mini', 'estimated_cost_usd' => null, 'input_usd_per_million_tokens' => null, 'output_usd_per_million_tokens' => null]);
    // An older model, last used three weeks ago.
    foreach (range(0, 3) as $index) {
        $call($now->subDays(21)->setTime(10, $index), [...$chunk, 'model' => 'gpt-5-luna-2026-05-01', 'estimated_cost_usd' => null]);
    }
}

/** Opens Translation AI at a width and waits for Alpine and the fonts. */
function visitTranslationAi(string $url, int $width, int $height = 900): mixed
{
    $page = visit($url)->resize($width, $height);

    waitForScript($page, "!! document.querySelector('.rg-admin-translation-ai__model')?._x_dataStack && document.fonts.status === 'loaded'");

    return $page;
}

/** What the screen shows: the URL's query, the figures, the bars, the rows, the pages. */
function translationAiBrowserScreen(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => ({
            query: Object.fromEntries(new URLSearchParams(location.search)),
            calls: document.querySelector('.rg-admin-translation-ai__figure-value')?.textContent.trim(),
            bars: document.querySelectorAll('.rg-admin-translation-ai__bar').length,
            rows: document.querySelectorAll('.rg-admin-translation-ai__call').length,
            range: document.querySelector('.rg-admin-pagination__range')?.textContent.replace(/\s+/g, ' ').trim(),
            model: document.querySelector('#rg-admin-translation-ai-model-trigger .rg-admin-combobox__value')?.textContent.trim(),
            period: document.querySelector('[aria-label="Period"] [aria-checked="true"]')?.textContent.trim(),
        }))()
    JS);
}

/** Clicks the option of a radio group by its text. */
function chooseTranslationAiRadio(mixed $page, string $group, string $label): void
{
    $page->script("[...document.querySelectorAll('[aria-label=\"{$group}\"] [role=\"radio\"]')].find((radio) => radio.textContent.trim().startsWith('{$label}')).click()");
}

beforeEach(function () {
    config(['translation.providers.openai.api_key' => 'test-key', 'translation.providers.openai.model' => 'gpt-6-luna']);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->now = CarbonImmutable::now('UTC');
    seedTranslationAiWeek($this->now);
});

it('draws Translation AI in Admin v2, inside the shell, in the Localization section after Translation Center', function () {
    $page = visitTranslationAi('/admin/translation-ai', 1440);

    expect($page->script(<<<'JS'
        (() => ({
            active: document.querySelector('#rg-admin-sidebar a[aria-current="page"] .rg-admin-nav-item__label')?.textContent.trim(),
            crumbs: [...document.querySelectorAll('.rg-admin-breadcrumb__item')].map((item) => item.textContent.trim()),
            filament: document.querySelectorAll('.fi-ta, .fi-ta-ctn, .fi-section, .fi-header-heading, .fi-modal').length,
            labels: [...document.querySelectorAll('#rg-admin-sidebar .rg-admin-nav-item__label')].map((label) => label.textContent.trim()),
            trigger: Math.round(document.getElementById('rg-admin-translation-ai-model-trigger').getBoundingClientRect().height),
            sections: [...document.querySelectorAll('.rg-admin-translation-ai__section')].map((section) => Math.round(section.getBoundingClientRect().top)),
            health: document.querySelector('[data-health]')?.textContent.trim(),
        }))()
    JS))->toMatchArray([
        'active' => 'Translation AI',
        'crumbs' => ['Localization', 'Translation AI'],
        'filament' => 0,
        'trigger' => 52,
        'health' => 'Healthy',
    ]);

    $screen = $page->script("[...document.querySelectorAll('#rg-admin-sidebar .rg-admin-nav-item__label')].map((label) => label.textContent.trim())");
    $tops = $page->script("[...document.querySelectorAll('.rg-admin-translation-ai__section')].map((section) => Math.round(section.getBoundingClientRect().top))");

    expect(array_search('Translation AI', $screen))->toBe(array_search('Translation Center', $screen) + 1)
        // Health, usage and failures side by side on a wide screen.
        ->and(array_unique($tops))->toHaveCount(1);

    $page->assertNoJavaScriptErrors();
});

it('fits every width without the page scrolling sideways, the calls table scrolling in its own card', function () {
    $page = visitTranslationAi('/admin/translation-ai', 1440);

    foreach ([1440 => 'side by side', 1280 => 'wrapped', 1024 => 'wrapped', 768 => 'wrapped', 390 => 'stacked'] as $width => $sections) {
        resizeAndSettle($page, $width, 900);

        $screen = $page->script(<<<'JS'
            (() => {
                const tops = [...document.querySelectorAll('.rg-admin-translation-ai__section')].map((section) => Math.round(section.getBoundingClientRect().top))
                const scroller = document.querySelector('.rg-admin-translation-ai__calls .rg-admin-table__scroll')
                const card = document.querySelector('.rg-admin-translation-ai__calls').getBoundingClientRect()

                return {
                    overflow: document.documentElement.scrollWidth > window.innerWidth,
                    rows: new Set(tops).size,
                    cardInside: card.left >= 0 && Math.round(card.right) <= window.innerWidth,
                    tableScrolls: scroller.scrollWidth > scroller.clientWidth,
                    model: Math.round(document.getElementById('rg-admin-translation-ai-model-trigger').getBoundingClientRect().right) <= window.innerWidth,
                }
            })()
        JS);

        expect($screen['overflow'])->toBeFalse("the page scrolls sideways at {$width}")
            ->and($screen['cardInside'])->toBeTrue("the calls card leaves the screen at {$width}")
            ->and($screen['model'])->toBeTrue("the model filter leaves the screen at {$width}")
            ->and($screen['rows'])->toBe(['side by side' => 1, 'wrapped' => 2, 'stacked' => 3][$sections], "the overview sections at {$width}")
            ->and($screen['tableScrolls'])->toBe($width < 1440, "the calls table at {$width}");

        // The model list opens inside the screen, never wider than it.
        $page->click('#rg-admin-translation-ai-model-trigger');
        waitForScript($page, "getComputedStyle(document.getElementById('rg-admin-translation-ai-model-popover')).display !== 'none'");

        expect($page->script("(() => { const box = document.getElementById('rg-admin-translation-ai-model-popover').getBoundingClientRect(); return box.left >= 0 && Math.round(box.right) <= window.innerWidth && document.documentElement.scrollWidth <= window.innerWidth })()"))
            ->toBeTrue("the model list at {$width}");

        $page->keys('#rg-admin-translation-ai-model-search', 'Escape');
        waitForScript($page, "getComputedStyle(document.getElementById('rg-admin-translation-ai-model-popover')).display === 'none'");
    }

    $page->assertNoJavaScriptErrors();
});

it('reads the period it is given, and keeps a new one in the URL with the chart that goes with it', function () {
    $page = visitTranslationAi('/admin/translation-ai', 1440);

    expect(translationAiBrowserScreen($page))->toMatchArray(['query' => [], 'bars' => 7, 'period' => '7 days']);

    chooseTranslationAiRadio($page, 'Period', '24 h');
    waitForScript($page, "new URLSearchParams(location.search).get('period')", '24h');
    waitForScript($page, "document.querySelectorAll('.rg-admin-translation-ai__bar').length", 24);

    chooseTranslationAiRadio($page, 'Period', '30 days');
    waitForScript($page, "document.querySelectorAll('.rg-admin-translation-ai__bar').length", 30);

    expect(translationAiBrowserScreen($page))->toMatchArray(['query' => ['period' => '30d'], 'period' => '30 days']);

    // A link opens the same view.
    expect(translationAiBrowserScreen(visitTranslationAi('/admin/translation-ai?period=24h', 1440)))->toMatchArray(['bars' => 24, 'period' => '24 h']);
});

it('narrows the history to a model from the combobox, and back to every model', function () {
    $page = visitTranslationAi('/admin/translation-ai?period=30d', 1440);
    $all = translationAiBrowserScreen($page)['calls'];

    $page->click('#rg-admin-translation-ai-model-trigger');
    waitForScript($page, "getComputedStyle(document.getElementById('rg-admin-translation-ai-model-popover')).display !== 'none'");
    $page->click('[role="option"][data-value="gpt-5-luna-2026-05-01"]');
    waitForScript($page, "new URLSearchParams(location.search).get('model')", 'gpt-5-luna-2026-05-01');
    waitForScript($page, "document.querySelector('#rg-admin-translation-ai-model-trigger .rg-admin-combobox__value')?.textContent.trim()", 'gpt-5-luna-2026-05-01');

    expect(translationAiBrowserScreen($page))->toMatchArray(['calls' => '4', 'rows' => 4])
        // The configuration's health does not follow the filter.
        ->and($page->script("document.querySelector('[data-health]').textContent.trim()"))->toBe('Healthy');

    $page->click('#rg-admin-translation-ai-model-trigger');
    waitForScript($page, "getComputedStyle(document.getElementById('rg-admin-translation-ai-model-popover')).display !== 'none'");
    $page->click('[role="option"][data-value=""]');
    waitForScript($page, "new URLSearchParams(location.search).has('model')", false);
    waitForScript($page, "document.querySelector('.rg-admin-translation-ai__figure-value')?.textContent.trim()", $all);
});

it('pages through the calls on the server, and a new filter starts again from the first page', function () {
    $page = visitTranslationAi('/admin/translation-ai', 1440);

    expect(translationAiBrowserScreen($page)['rows'])->toBe(25);

    $page->click('.rg-admin-pagination [aria-label="Page 2"]');
    waitForScript($page, "new URLSearchParams(location.search).get('page')", '2');
    waitForScript($page, "document.querySelector('.rg-admin-pagination [aria-current=\"page\"]')?.textContent.trim()", '2');

    expect(translationAiBrowserScreen($page)['range'])->toStartWith('26–');

    chooseTranslationAiRadio($page, 'Show calls', 'With issues');
    waitForScript($page, "new URLSearchParams(location.search).get('calls')", 'issues');
    waitForScript($page, "new URLSearchParams(location.search).has('page')", false);

    expect(translationAiBrowserScreen($page))->toMatchArray(['rows' => 8])
        ->and($page->script("[...document.querySelectorAll('.rg-admin-translation-ai__call')].every((row) => row.dataset.result !== 'success')"))->toBeTrue();
});

it('reads everything again on Refresh, and says so in a toast', function () {
    $page = visitTranslationAi('/admin/translation-ai?period=24h', 1440);
    $before = (int) translationAiBrowserScreen($page)['calls'];

    TranslationAiProviderCall::factory()->create(['completed_at' => CarbonImmutable::now('UTC')]);
    $page->click('Refresh');

    waitForScript($page, "document.querySelector('.rg-admin-translation-ai__figure-value')?.textContent.trim()", (string) ($before + 1));
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast')].some((toast) => toast.textContent.includes('Translation AI refreshed'))");
});

it('folds the configuration away until it is asked for', function () {
    $page = visitTranslationAi('/admin/translation-ai', 1440);
    $toggle = '.rg-admin-translation-ai__configuration-toggle';

    expect($page->script("[document.querySelector('{$toggle}').getAttribute('aria-expanded'), getComputedStyle(document.getElementById('rg-admin-translation-ai-configuration')).display]"))->toBe(['false', 'none']);

    $page->click($toggle);
    waitForScript($page, "document.querySelector('{$toggle}').getAttribute('aria-expanded')", 'true');
    waitForScript($page, "getComputedStyle(document.getElementById('rg-admin-translation-ai-configuration')).display !== 'none'");

    expect($page->script("document.getElementById('rg-admin-translation-ai-configuration').innerText"))->toContain('Queue worker timeout')->toContain('Not available');

    $page->click($toggle);
    waitForScript($page, "getComputedStyle(document.getElementById('rg-admin-translation-ai-configuration')).display", 'none');
});

it('follows a running generation until it completes, and then stops reading the store', function () {
    $store = app(ProjectTranslationGenerationStore::class);
    $batch = $store->create($this->admin->id, 'de', translationGenerationPlan('de', 1, 2), $this->now);
    $page = visitTranslationAi('/admin/translation-ai', 1440);
    $block = '.rg-admin-translation-ai__generations';

    expect($page->script("document.querySelector('{$block} .rg-admin-badge').textContent.trim()"))->toBe('Queued');

    // It is read again only while it is on screen.
    $page->script("document.querySelector('{$block}').scrollIntoView()");

    $store->claim($batch['meta']['id'], 'c1', CarbonImmutable::now('UTC'));
    $store->complete($batch['meta']['id'], 'c1', [
        'categories:11:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Eins'],
        'categories:12:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Zwei'],
    ], CarbonImmutable::now('UTC'));

    waitForScript($page, "document.querySelector('{$block} .rg-admin-badge')?.textContent.trim()", 'Completed', 12.0);
    waitForScript($page, "document.querySelector('{$block}').getAttributeNames().some((name) => name.startsWith('wire:poll'))", false);
});
