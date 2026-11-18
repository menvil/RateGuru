<?php

use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\ProjectSettings;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Games in a real browser: the tab at each width — the games to
 * choose from, the game's own filters and cards — wrapping and scrolling
 * inside itself, never the page; and another game chosen from the chooser,
 * shown and put in the address, a version of the game before leaving it, and
 * the game leaving the address with its tab.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $draft = publishableGameDraft(1);
    actingAs($draft['admin']);

    $draft['game']->forceFill(['title' => 'Trust Your Taste'])->save();
    $this->first = $draft['game'];
    $this->version = liveGame($draft);

    // Two guests opened it; one played.
    foreach (['guest-a', 'guest-b'] as $guest) {
        (new GameEvent)->forceFill(['game_id' => $this->first->id, 'version_id' => $this->version->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', $guest), 'type' => GameEventType::View, 'occurred_at' => now()])->save();
    }

    GameAttempt::factory()->create(['version_id' => $this->version->id, 'visitor_hash' => hash('sha256', 'guest-a')]);

    $this->second = Game::factory()->status(GameStatus::Live)->create(['title' => 'Swipe Challenge']);
});

/** Opens an Analytics → Games address and waits for the game's figures it reads once it is in the browser (wire:init). */
function visitAnalyticsGames(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"game-analytics-filters\"]') !== null", true, 10.0);

    return $page;
}

/** The game the chooser has checked. */
function chosenAnalyticsGame(mixed $page): ?string
{
    return $page->script("document.querySelector('[data-testid=\"game-analytics-games\"] [aria-checked=\"true\"]')?.textContent.trim() ?? null");
}

it('draws the tab at every width, the chooser and the cards wrapping and scrolling inside themselves, never the page', function (int $width) {
    $page = visitAnalyticsGames('/admin/analytics?tab=games', $width);

    expect($page->script("[...document.querySelectorAll('.rg-admin-analytics__tabs .rg-admin-tab')].map((tab) => tab.textContent.trim())"))->toBe(['Overview', 'Landings', 'Games', 'Experiments', 'Geography'])
        ->and(chosenAnalyticsGame($page))->toBe('Trust Your Taste')
        ->and($page->script("document.querySelector('[data-testid=\"game-analytics-kpis\"] .rg-admin-stat__value')?.textContent.trim()"))->toBe('2')
        // The page's own period picker and device are the shell's: the bar has neither.
        ->and($page->script("document.querySelector('#rg-admin-games-analytics-period, #rg-admin-games-analytics-device') === null"))->toBeTrue()
        ->and($page->script(<<<'JS'
            (() => {
                const chooser = document.querySelector('[data-testid="game-analytics-games"]')
                const scroll = document.querySelector('[data-testid="game-analytics-breakdown"] .rg-admin-table__scroll')
                const grid = scroll.querySelector('[role="table"]')

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    chooserFits: chooser.getBoundingClientRect().right <= document.querySelector('[data-testid="game-analytics-filters"]').getBoundingClientRect().right + 1,
                    scrollsWhenNarrower: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'chooserFits' => true, 'scrollsWhenNarrower' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('shows another game chosen from the chooser, puts it in the address, and leaves a version of the game before behind', function () {
    $page = visitAnalyticsGames('/admin/analytics?tab=games&version='.$this->version->id);
    $param = fn (string $name): ?string => $page->script("new URLSearchParams(location.search).get('{$name}')");

    expect(chosenAnalyticsGame($page))->toBe('Trust Your Taste')
        ->and($param('game'))->toBe((string) $this->first->id)
        ->and($param('version'))->toBe((string) $this->version->id);

    $page->click('[data-testid="game-analytics-games"] [data-game="'.$this->second->id.'"]');
    waitForScript($page, "document.querySelector('[data-testid=\"game-analytics-games\"] [aria-checked=\"true\"]')?.textContent.trim()", 'Swipe Challenge', 10.0);
    waitForScript($page, "document.body.textContent.includes('Nothing to measure yet')", true, 10.0);

    expect($param('game'))->toBe((string) $this->second->id)
        ->and($param('version'))->toBeNull();

    // Back to the first: its figures again, its versions to choose from.
    $page->click('[data-testid="game-analytics-games"] [data-game="'.$this->first->id.'"]');
    waitForScript($page, "document.querySelector('[data-testid=\"game-analytics-kpis\"] .rg-admin-stat__value')?.textContent.trim()", '2', 10.0);
    $page->click('#rg-admin-games-analytics-version');
    $page->click('#rg-admin-games-analytics-version-menu [role=menuitemradio]:has-text("V1")');
    waitForScript($page, "new URLSearchParams(location.search).get('version')", (string) $this->version->id, 10.0);

    expect($page->script("document.querySelector('#rg-admin-games-analytics-version')?.textContent.trim().replace(/\\s+/g, ' ')"))->toBe('Versions: V1');

    // Back to Overview, the game and its filters leave the address with their tab.
    $page->click('.rg-admin-analytics__tabs .rg-admin-tab:has-text("Overview")');
    waitForScript($page, 'location.search', '', 10.0);

    $page->assertNoJavaScriptErrors();
});
