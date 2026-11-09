<?php

use App\Actions\Games\CreateGameAction;
use App\Data\Games\GameGeneralData;
use App\Enums\GameAudience;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameFlowStep;
use App\Models\GameRoundOption;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\ProjectSettings;

use function Pest\Laravel\actingAs;

/**
 * Growth → Games in a real browser: the library and every tab of the editor
 * at each width, never wider than the screen; a new game made from the top
 * bar; a round's option filled from the post picker and saved; a version
 * published and given the traffic; and the preview playing in its frame.
 *
 * No picture is uploaded here: the browser test server hands the application
 * no uploaded files. Uploads are covered through Livewire in the Feature tests.
 *
 * One kind of page per test: the test server reads the top bar's actions from
 * the first page it drew.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
    actingAs($this->draft['admin']);
});

function pageOverflows(mixed $page): bool
{
    return (bool) $page->script('document.documentElement.scrollWidth > window.innerWidth');
}

it('draws the library at every width, the table scrolling inside its card and never the page', function (int $width) {
    $page = visitAdminEditor('/admin/games', $width);

    expect($page->script(<<<'JS'
        (() => {
            const scroll = document.querySelector('.rg-admin-table__scroll')
            const grid = scroll.querySelector('[role="table"]')

            return {
                columns: [...document.querySelectorAll('[role="columnheader"]')].map((cell) => cell.textContent.trim()),
                scrollsWhenNarrower: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
            }
        })()
    JS))->toBe([
        'columns' => ['Game', 'Last version', 'Status', 'Plays', 'Completion', 'Sign-ups', 'Last updated', 'Actions'],
        'scrollsWhenNarrower' => true,
    ])->and(pageOverflows($page))->toBeFalse();

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('draws every tab of the editor at every width, never wider than the screen', function (string $tab, int $width) {
    liveGame($this->draft);
    $page = visitAdminEditor(GameResource::getUrl($tab, ['record' => $this->draft['game']]), $width);

    // A tab's badge, such as the number of versions, follows its name.
    expect($page->script("[...document.querySelectorAll('.rg-admin-tab')].map((tab) => tab.textContent.trim().split(/\\s+/)[0])"))
        ->toBe(['General', 'Content', 'Flow', 'Versions', 'Traffic', 'Analytics', 'Preview'])
        ->and($page->script("document.querySelector('.rg-admin-tab[aria-current=\"page\"]')?.textContent.trim().split(/\\s+/)[0]"))
        ->toBe(['edit' => 'General', 'content' => 'Content', 'flow' => 'Flow', 'versions' => 'Versions', 'traffic' => 'Traffic', 'analytics' => 'Analytics', 'preview' => 'Preview'][$tab])
        ->and(pageOverflows($page))->toBeFalse();

    $page->assertNoJavaScriptErrors();
})->with(['edit', 'content', 'flow', 'versions', 'traffic', 'analytics', 'preview'])->with([1440, 1024, 390]);

it('makes a new game from the top bar and opens its General tab', function () {
    $page = visitAdminEditor('/admin/games/create');

    $page->type('#rg-admin-game-title-field', 'Taste Test');
    waitForScript($page, "document.getElementById('rg-admin-game-slug')?.value", 'taste-test', 10.0);
    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');
    waitForScript($page, "location.pathname.startsWith('/admin/games/') && ! location.pathname.endsWith('/create')", true, 10.0);

    $game = Game::query()->where('slug', 'taste-test')->sole();

    expect($page->script('location.pathname'))->toBe('/admin/games/'.$game->id)
        ->and($game->contentSets()->count())->toBe(1)
        ->and($game->flows()->count())->toBe(1);

    $page->assertNoJavaScriptErrors();
});

it('fills a round’s option from the post picker and saves it', function () {
    $game = app(CreateGameAction::class)->handle($this->draft['admin'], new GameGeneralData(title: 'Picker Game', internalName: 'Picker Game', slug: 'picker-game'));
    $post = gamePost(900, 100);
    $page = visitAdminEditor(GameResource::getUrl('content', ['record' => $game]), 1440);

    expect($page->script('Alpine.store("adminEditor").changes'))->toBe(0);

    $page->click('aside button[wire\\:click="addRound"]');
    waitForScript($page, "!! document.querySelector('button[wire\\\\:click^=\"openPicker\"]')", true, 10.0);
    // A round only the draft has is unsaved.
    waitForScript($page, 'Alpine.store("adminEditor").changes > 0', true, 10.0);
    $page->click('button[wire\\:click="openPicker(0, 0)"]');
    waitForScript($page, "!! document.querySelector('#rg-admin-game-picker .rg-admin-games__picker-item')", true, 10.0);
    $page->click('#rg-admin-game-picker button[wire\\:click="pickPost('.$post->id.')"]');
    waitForScript($page, "document.body.textContent.includes('#".$post->id."') && ! document.querySelector('#rg-admin-game-picker .rg-admin-games__picker-item')", true, 10.0);
    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');
    waitForScript($page, '! (window.Alpine?.store("adminEditor")?.changes > 0)', true, 10.0);

    expect(GameRoundOption::query()->where('post_id', $post->id)->exists())->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('adds a step at the end of a flow, counts it as unsaved, and saves it in its place', function () {
    $page = visitAdminEditor(GameResource::getUrl('flow', ['record' => $this->draft['game']]), 1440);

    expect($page->script('Alpine.store("adminEditor").changes'))->toBe(0);

    $page->click('button[wire\\:click="insertStep(\'share\')"]');
    waitForScript($page, 'Alpine.store("adminEditor").changes > 0', true, 10.0);
    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');
    waitForScript($page, 'Alpine.store("adminEditor").changes === 0', true, 10.0);

    expect(GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->orderBy('position')->pluck('type')->map(fn ($type) => $type->value)->all())
        ->toBe(['landing', 'game', 'full_result', 'share']);

    $page->assertNoJavaScriptErrors();
});

it('publishes a version from Versions after asking, and gives it every visitor in Traffic', function () {
    $page = visitAdminEditor(GameResource::getUrl('versions', ['record' => $this->draft['game']]), 1280);

    $page->click('button[wire\\:click="askPublish"]');
    waitForScript($page, "!! document.querySelector('#rg-admin-game-publish button[wire\\\\:click=\"publish\"]')", true, 10.0);
    $page->click('#rg-admin-game-publish button[wire\\:click="publish"]');
    waitForScript($page, "document.body.textContent.includes('Publish V2')", true, 10.0);

    $version = GameVersion::query()->sole();
    expect($version->number)->toBe(1);

    $page = visitAdminEditor(GameResource::getUrl('traffic', ['record' => $this->draft['game']]), 1280);
    $page->click('button[wire\\:click="addVersion(\'anonymous\', '.$version->id.')"]');
    waitForScript($page, "!! document.getElementById('rg-admin-share-anonymous-".$version->id."')", true, 10.0);
    $page->click('button[wire\\:click="splitEvenly(\'anonymous\')"]');
    waitForScript($page, "document.getElementById('rg-admin-share-anonymous-".$version->id."')?.value", '100', 10.0);
    $page->click('button[wire\\:click="apply(\'anonymous\')"]');
    waitForScript($page, "document.body.textContent.includes('Current')", true, 10.0);

    expect(GameTrafficPeriod::query()->where('audience', GameAudience::Anonymous->value)->sole()->shares())->toBe([$version->id => 100]);

    $page->assertNoJavaScriptErrors();
});

it('plays the draft in the preview’s frame, recording nothing', function () {
    $page = visitAdminEditor(GameResource::getUrl('preview', ['record' => $this->draft['game']]), 1440);

    waitForScript($page, <<<'JS'
        (() => {
            const frame = document.querySelector('.rg-admin-games__frame')
            const doc = frame?.contentDocument

            return !! doc?.querySelector('[data-testid="game-preview-banner"]') && !! doc?.querySelector('[data-testid="game-start"]')
        })()
    JS, true, 15.0);

    $page->script("document.querySelector('.rg-admin-games__frame').contentDocument.querySelector('[data-testid=\"game-start\"]').click()");
    waitForScript($page, "!! document.querySelector('.rg-admin-games__frame').contentDocument.querySelector('[data-testid=\"game-option-0\"]')", true, 10.0);

    expect(GameAttempt::query()->exists())->toBeFalse()
        ->and(GameEvent::query()->exists())->toBeFalse();

    $page->assertNoJavaScriptErrors();
});
