<?php

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Growth → Experiments in a real browser: the list and a period's results at
 * each width, the tables scrolling inside their cards and never the page; a
 * period opened from the list, another chosen from its switcher, deleted —
 * back to the list without it — and brought back with Undo.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    $game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste']);
    $this->ended = comparisonPeriod($game, [7 => 50, 8 => 50], now()->subDays(30)->toDateTimeString(), now()->subDays(10)->toDateTimeString());
    $this->current = comparisonPeriod($game, [7 => 80, 8 => 20], now()->subDays(10)->toDateTimeString());

    foreach ([$this->ended, $this->current] as $period) {
        foreach ($period->versions as $version) {
            foreach (range(1, 3) as $ignored) {
                periodVisitor($period, $version, ['completed' => true]);
            }
        }
    }
});

/** Whether the page is wider than the screen, and whether a table scrolls exactly when it is wider than its card. */
function experimentsLayout(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const scroll = document.querySelector('.rg-admin-table__scroll')
            const grid = scroll.querySelector('[role="table"]')

            return {
                pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                scrollsWhenNarrower: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
            }
        })()
    JS);
}

/** Opens an Experiments address and waits for the figures it reads once it is in the browser (wire:init). */
function visitExperiments(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('.rg-admin-experiments') !== null && document.querySelector('.rg-admin-experiments__loading') === null", true, 10.0);

    return $page;
}

it('draws the list and a period’s results at every width, never wider than the screen', function (int $width) {
    $list = visitExperiments('/admin/experiments', $width);

    expect($list->script("[...document.querySelectorAll('[role=\"columnheader\"]')].map((cell) => cell.textContent.trim())"))
        ->toBe(['Game', 'Group', 'Period', 'Versions', 'Visitors', 'Data', 'Status', 'Results'])
        ->and(experimentsLayout($list))->toBe(['pageOverflows' => false, 'scrollsWhenNarrower' => true]);
    $list->assertNoJavaScriptErrors();

    $period = visitExperiments('/admin/experiments?period='.$this->ended->id, $width);

    expect($period->script("document.getElementById('rg-admin-experiments-period-title')?.textContent.trim()"))->toBe('Trust Your Taste · Anonymous visitors')
        ->and(experimentsLayout($period))->toBe(['pageOverflows' => false, 'scrollsWhenNarrower' => true]);
    $period->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('opens a period from the list, switches to another, deletes it and brings it back with Undo', function () {
    $page = visitExperiments('/admin/experiments');

    expect($page->script("[...document.querySelectorAll('.rg-admin-experiments__row')].map((row) => row.id)"))
        ->toBe(['rg-admin-experiments-period-'.$this->current->id, 'rg-admin-experiments-period-'.$this->ended->id]);

    // The current period's results, from its row.
    $page->click('#rg-admin-experiments-period-'.$this->current->id.' .rg-admin-button');
    waitForScript($page, "document.querySelector('.rg-admin-experiments__period[aria-current=\"page\"] .rg-admin-experiments__period-mix')?.textContent.trim()", 'V7 80% · V8 20%', 10.0);
    waitForScript($page, '!! window.Livewire && window.Livewire.all().length > 0', true, 10.0);

    expect($page->script("[...document.querySelectorAll('.rg-admin-topbar__actions .rg-admin-button')].map((button) => button.textContent.trim())"))->toBe(['Open Traffic']);

    // The ended one, from the switcher: it can be deleted.
    $page->click('.rg-admin-experiments__period:not([aria-current])');
    waitForScript($page, "document.querySelector('.rg-admin-experiments__period[aria-current=\"page\"] .rg-admin-experiments__period-mix')?.textContent.trim()", 'V7 50% · V8 50%', 10.0);
    waitForScript($page, '!! window.Livewire && window.Livewire.all().length > 0', true, 10.0);

    $page->click('.rg-admin-topbar__actions button.rg-admin-button');

    // Back on the list, without it, saying so with Undo.
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Period deleted. Its numbers stay in the database.', 10.0);
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-experiments__row')].map((row) => row.id).join()", 'rg-admin-experiments-period-'.$this->current->id, 10.0);

    expect($this->ended->fresh()->hidden_at)->not->toBeNull();

    $page->click('.rg-admin-toast .rg-admin-toast__action');
    waitForScript($page, "document.querySelectorAll('.rg-admin-experiments__row').length", 2, 10.0);

    expect($this->ended->fresh()->hidden_at)->toBeNull();

    $page->assertNoJavaScriptErrors();
});
