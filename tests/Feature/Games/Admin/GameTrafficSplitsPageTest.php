<?php

use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameAudience;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficSourceStatus;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameTraffic;
use App\Filament\Resources\Games\Pages\GameVersions;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\User;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Games\Traffic\TrafficScope;
use Livewire\Livewire;

/**
 * A game's Traffic tab, under the general split: its splits for a source,
 * each with its shares, visitors, Edit, End and results, and the form that
 * sets one — a source of Traffic sources, one of its campaigns or all of
 * them, and the versions' shares checked as the general split's are. Saving
 * starts the split's own period, or changes the split already open for that
 * source and campaign; a split changed meanwhile is never overwritten. End
 * asks first. The card says splits send nobody while Record visits is off,
 * and offers the way to Traffic sources when there is no source to choose.
 * A moderator can do none of it.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
    $this->v1 = liveGame($this->draft);
    $this->v2 = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game'], $this->draft['set']->id, $this->draft['flow']->id);
    $this->game = $this->draft['game']->fresh();
    $this->exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    $this->pop = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $this->campaign = TrafficCampaign::factory()->create(['source_id' => $this->pop->id, 'external_id' => '881377', 'status' => TrafficCampaignStatus::Active]);
    $this->actingAs($this->draft['admin']);
});

function splitOf(object $test, TrafficScope $scope): ?GameTrafficPeriod
{
    return app(GameTrafficQuery::class)->currentPeriod((int) $test->game->id, GameAudience::Anonymous, $scope);
}

it('starts a split for a source from the Traffic tab, as the general split is set', function () {
    $page = Livewire::test(GameTraffic::class, ['record' => $this->game->id])
        ->assertSee('Splits for a source')
        ->assertSee('No splits for a source yet')
        ->call('openSplit')
        ->assertSee('Choose a source for the split.');

    // Ended sources and archived campaigns are not offered.
    TrafficSource::factory()->create(['name' => 'Gone network', 'status' => TrafficSourceStatus::Ended]);
    TrafficCampaign::factory()->create(['source_id' => $this->pop->id, 'external_id' => '100001', 'status' => TrafficCampaignStatus::Archived]);

    $page->set('split.source', (string) $this->pop->id);
    expect(collect($page->viewData('splitForm')['sources'])->pluck('label')->all())->toBe(['ExoClick tests · Ad network', 'PopAds main · Ad network'])
        ->and(collect($page->viewData('splitForm')['campaigns'])->pluck('label')->all())->toBe(['Campaign 881377']);

    $page->set('split.campaign', (string) $this->campaign->id)
        ->call('addSplitVersion', $this->v1->id)
        ->call('addSplitVersion', $this->v2->id)
        ->set('split.shares.'.$this->v1->id, '30')
        ->assertSee('Each share is a whole number from 1 to 100.')
        ->set('split.shares.'.$this->v2->id, '60')
        ->assertSee('Shares add up to 90%. Make them 100% to apply.')
        ->set('split.shares.'.$this->v2->id, '70')
        ->assertSee('Shares add up to 100%. Saving starts the split.')
        ->call('saveSplit')
        ->assertDispatched('rg-admin-toast', message: 'Split saved. Its own period started.', tone: 'success')
        ->assertSet('split', null)
        ->assertSee('PopAds main · Campaign 881377 · V1 30% · V2 70%');

    expect(splitOf($this, TrafficScope::campaign($this->pop->id, $this->campaign->id))->shares())->toBe([$this->v1->id => 30, $this->v2->id => 70])
        // The general split is untouched, and now reads as everyone else's.
        ->and(splitOf($this, TrafficScope::everyone())->shares())->toBe([$this->v1->id => 100]);
    $page->assertSee('Everyone else: each new visitor no split for a source covers gets one version');
});

it('says splits send nobody while Record visits is off, with the way to switch it on', function () {
    recordTraffic(false);

    Livewire::test(GameTraffic::class, ['record' => $this->game->id])
        ->assertSee('Visits aren’t recorded, so no click knows its source and splits send nobody.')
        ->assertSeeHtml('href="'.TrafficSourcesPage::getUrl(['tab' => 'sources']).'"');

    recordTraffic();

    Livewire::test(GameTraffic::class, ['record' => $this->game->id])
        ->assertSee('Splits for a source')
        ->assertDontSee('Visits aren’t recorded');
});

it('offers the way to Traffic sources rather than an empty list when there is no source to split for', function () {
    TrafficSource::query()->update(['status' => TrafficSourceStatus::Ended]);

    Livewire::test(GameTraffic::class, ['record' => $this->game->id])
        ->call('openSplit')
        ->assertSee('No ad network or banner to split for yet: add one under Traffic sources.')
        ->assertSee('Open Traffic sources')
        ->assertDontSee('Choose a source…')
        ->call('closeSplit')
        ->assertDontSee('No ad network or banner to split for yet');
});

it('takes up the split already open for the source chosen, and changes it', function () {
    $split = gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v1->id => 100]);

    $page = Livewire::test(GameTraffic::class, ['record' => $this->game->id])
        ->call('openSplit')
        ->set('split.source', (string) $this->exo->id)
        ->assertSet('split.shares', [$this->v1->id => 100])
        ->assertSee('Shares add up to 100%. Saving changes this split.')
        ->call('addSplitVersion', $this->v2->id)
        ->call('spreadSplitEvenly')
        ->call('saveSplit')
        ->assertDispatched('rg-admin-toast', message: 'Split saved. Its current period had no visitors yet, so it was updated.', tone: 'success');

    expect(splitOf($this, TrafficScope::source($this->exo->id)))->id->toBe($split->id)
        ->and(splitOf($this, TrafficScope::source($this->exo->id))->shares())->toBe([$this->v1->id => 50, $this->v2->id => 50]);

    // Edit opens the same split's form.
    $page->call('openSplit', $split->id)
        ->assertSet('split.source', (string) $this->exo->id)
        ->assertSet('split.campaign', '')
        ->assertSet('split.shares', [$this->v1->id => 50, $this->v2->id => 50]);
});

it('saves nothing over a split changed since the form drew it, and draws it again', function () {
    $page = Livewire::test(GameTraffic::class, ['record' => $this->game->id])
        ->call('openSplit')
        ->set('split.source', (string) $this->exo->id)
        ->call('addSplitVersion', $this->v2->id)
        ->set('split.shares.'.$this->v2->id, '100');

    gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v1->id => 100]);

    $page->call('saveSplit')
        ->assertDispatched('rg-admin-toast', message: 'This split changed after you opened it, so nothing was saved. It now shows as it is.', tone: 'error')
        ->assertSet('split.shares', [$this->v1->id => 100]);

    expect(splitOf($this, TrafficScope::source($this->exo->id))->shares())->toBe([$this->v1->id => 100]);
});

it('ends a split once asked, keeping the visitors it sent on their version', function () {
    $split = gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);

    Livewire::test(GameTraffic::class, ['record' => $this->game->id])
        ->assertSee('ExoClick tests · V2 100%')
        ->call('askEndSplit', $split->id)
        ->assertSee('End this split?')
        ->assertSee('Paid clicks of ExoClick tests go back to the next split that covers them.')
        ->call('closeEndSplit')
        ->assertDontSee('End this split?')
        ->call('askEndSplit', $split->id)
        ->call('endSplit')
        ->assertDispatched('rg-admin-toast', message: 'Split ended. Its period stays in the history.', tone: 'success')
        ->assertSee('No splits for a source yet');

    expect($split->fresh())->ended_at->not->toBeNull()->open_scope->toBeNull();
});

it('keeps the shares typed in the form of one split while another is ended, and starts over the form of the split ended', function () {
    $exo = gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v1->id => 100]);
    $campaign = gameSplit($this->draft, TrafficScope::campaign($this->pop->id, $this->campaign->id), [$this->v2->id => 100]);

    $page = Livewire::test(GameTraffic::class, ['record' => $this->game->id])
        ->call('openSplit', $exo->id)
        ->call('addSplitVersion', $this->v2->id)
        ->set('split.shares.'.$this->v1->id, '40')
        ->set('split.shares.'.$this->v2->id, '60')
        ->call('askEndSplit', $campaign->id)
        ->call('endSplit')
        ->assertDispatched('rg-admin-toast', message: 'Split ended. Its period stays in the history.', tone: 'success')
        ->assertSet('split.shares', [$this->v1->id => '40', $this->v2->id => '60'])
        ->assertSee('Shares add up to 100%. Saving changes this split.');

    // The form's own split ended: what is typed stays, and saving starts the split again.
    $page->call('askEndSplit', $exo->id)
        ->call('endSplit')
        ->assertSet('splitApplied', [])
        ->assertSet('split.shares', [$this->v1->id => '40', $this->v2->id => '60'])
        ->assertSee('Shares add up to 100%. Saving starts the split.');
});

it('offers the campaign a split is for though two hundred newer campaigns of its source come first', function () {
    $old = TrafficCampaign::factory()->create(['source_id' => $this->pop->id, 'external_id' => '100777', 'status' => TrafficCampaignStatus::Active, 'first_click_at' => now()->subYear()]);
    $split = gameSplit($this->draft, TrafficScope::campaign($this->pop->id, $old->id), [$this->v2->id => 100]);
    TrafficCampaign::factory()->count(200)->create(['source_id' => $this->pop->id, 'status' => TrafficCampaignStatus::Active]);

    $campaigns = collect(Livewire::test(GameTraffic::class, ['record' => $this->game->id])
        ->call('openSplit', $split->id)
        ->assertSet('split.campaign', (string) $old->id)
        ->viewData('splitForm')['campaigns']);

    expect($campaigns->pluck('id'))->toContain($old->id)
        ->and($campaigns)->toHaveCount(201)
        ->and($campaigns->pluck('id')->unique())->toHaveCount(201);
});

it('counts a version given a share by a split alone as in traffic on Versions', function () {
    gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);

    $versions = collect(Livewire::test(GameVersions::class, ['record' => $this->game->id])->viewData('versions'))->keyBy('id');

    expect($versions[$this->v2->id])->inTraffic->toBeTrue()->traffic->toBe('1 source split')
        ->and($versions[$this->v1->id]['traffic'])->toBe('Anonymous 100% · Logged in 100%');
});

it('lets a moderator do nothing with splits', function () {
    $split = gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);
    $this->actingAs(User::factory()->moderator()->create());

    $this->get(GameResource::getUrl('traffic', ['record' => $this->game]))->assertForbidden();

    expect(splitOf($this, TrafficScope::source($this->exo->id))->id)->toBe($split->id)
        ->and(GameVersion::query()->count())->toBe(2);
});
