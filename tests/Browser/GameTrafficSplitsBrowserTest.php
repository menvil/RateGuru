<?php

use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameAudience;
use App\Filament\Resources\Games\GameResource;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\ProjectSettings;
use App\Models\TrafficSource;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Games\Traffic\TrafficScope;

use function Pest\Laravel\actingAs;

/**
 * A game's splits for a source and its test links in a real browser: a split
 * set on the Traffic tab — a source chosen, versions added and spread evenly,
 * saved — at a width where the form and the list sit side by side with the
 * general split; and Copy test link in Preview, which puts the version's
 * signed link on the clipboard and says so only once the clipboard took
 * it, the link then playing the version and recording nothing — every
 * Preview action staying on a phone's screen beside it.
 *
 * One kind of page per test: the test server reads the top bar's actions from
 * the first page it drew.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
    $this->v1 = liveGame($this->draft);
    $this->v2 = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game'], $this->draft['set']->id, $this->draft['flow']->id);
    $this->exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    actingAs($this->draft['admin']);
});

it('sets a split for a source on the Traffic tab, listed under the general split once saved', function (int $width) {
    $page = visitAdminEditor(GameResource::getUrl('traffic', ['record' => $this->draft['game']]), $width);

    $page->click('[data-testid="traffic-splits"] button[wire\\:click="openSplit"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"traffic-split-source\"]')", true, 10.0);
    $page->script("(() => { const field = document.querySelector('[data-testid=\"traffic-split-source\"]'); field.value = '{$this->exo->id}'; field.dispatchEvent(new Event('change', { bubbles: true })) })()");
    waitForScript($page, "!! document.querySelector('button[wire\\\\:click=\"addSplitVersion({$this->v1->id})\"]')", true, 10.0);
    $page->click("button[wire\\:click=\"addSplitVersion({$this->v1->id})\"]");
    waitForScript($page, "!! document.getElementById('rg-admin-split-share-{$this->v1->id}')", true, 10.0);
    $page->click("button[wire\\:click=\"addSplitVersion({$this->v2->id})\"]");
    waitForScript($page, "!! document.getElementById('rg-admin-split-share-{$this->v2->id}')", true, 10.0);
    $page->click('button[wire\\:click="spreadSplitEvenly"]');
    waitForScript($page, "document.getElementById('rg-admin-split-share-{$this->v2->id}')?.value", '50', 10.0);

    expect($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();

    $page->click('button[wire\\:click="saveSplit"]');
    waitForScript($page, "document.querySelector('[data-testid=\"traffic-split\"]')?.textContent.includes('ExoClick tests · V1 50% · V2 50%')", true, 10.0);

    expect(app(GameTrafficQuery::class)->currentPeriod((int) $this->draft['game']->id, GameAudience::Anonymous, TrafficScope::source($this->exo->id))->shares())
        ->toBe([$this->v1->id => 50, $this->v2->id => 50])
        ->and($page->script("!! document.querySelector('[data-testid=\"traffic-split-form\"]')"))->toBeFalse()
        ->and($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();

    $page->assertNoJavaScriptErrors();
})->with([1440, 1024, 390]);

it('copies a version’s test link from Preview, which plays the version and records nothing', function () {
    $page = visitAdminEditor(GameResource::getUrl('preview', ['record' => $this->draft['game'], 'source' => (string) $this->v2->id]), 1440);

    // Refused: the toast says nothing was copied, never that it was.
    $page->script("(() => { navigator.clipboard.writeText = () => Promise.reject(new DOMException('Denied', 'NotAllowedError')) })()");
    $page->click('[data-testid="game-preview-test-link"]');
    waitForScript($page, "document.body.textContent.includes('The browser refused the clipboard, so nothing was copied.')", true, 10.0);

    expect($page->script("document.body.textContent.includes('Test link copied')"))->toBeFalse();

    // A headless browser may not write to the clipboard: what is written is kept for the test to read.
    $page->script("() => { Object.defineProperty(navigator.clipboard, 'writeText', { value: (text) => { window.copiedLink = text; return Promise.resolve() } }); return true; }");
    $page->click('[data-testid="game-preview-test-link"]');
    waitForScript($page, "document.body.textContent.includes('Test link copied. It plays V2 for 7 days and records nothing.')", true, 10.0);

    $link = (string) $page->script('window.copiedLink');
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect(parse_url($link, PHP_URL_PATH))->toBe('/games/'.$this->draft['game']->slug)
        ->and($query['v'] ?? null)->toBe((string) $this->v2->id)
        ->and($query)->toHaveKey('signature');

    $this->get($link)->assertOk()->assertSee('Preview — V2 — nothing is recorded');

    expect(GameAssignment::query()->exists())->toBeFalse()
        ->and(GameAttempt::query()->exists())->toBeFalse()
        ->and(GameEvent::query()->exists())->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('keeps every Preview action on a phone’s screen, Copy test link among them', function () {
    $page = visitAdminEditor(GameResource::getUrl('preview', ['record' => $this->draft['game'], 'source' => (string) $this->v2->id]), 390);

    expect($page->script("!! document.querySelector('[data-testid=\"game-preview-test-link\"]')"))->toBeTrue()
        ->and($page->script("[...document.querySelectorAll('.rg-admin-games__preview-actions > *')].every((action) => { const box = action.getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth })"))->toBeTrue()
        ->and($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();

    $page->assertNoJavaScriptErrors();
});
