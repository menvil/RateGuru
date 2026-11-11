<?php

use App\Enums\GameAudience;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameTraffic;
use App\Filament\Resources\Games\Pages\GameVersions;
use App\Models\GameRoundOption;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
    $this->actingAs($this->draft['admin']);
});

it('draws the publish report for the chosen set and flow, and publishes once asked', function () {
    $this->get(GameResource::getUrl('versions', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('2 rounds ready.')
        ->assertSee('Every result has a profile.')
        ->assertSee('Flow is valid: Landing → Game → Full Result.')
        ->assertSee('Publish V1')
        ->assertSee('No versions yet');

    Livewire::test(GameVersions::class, ['record' => $this->draft['game']->id])
        ->call('askPublish')
        ->assertSee('Publish V1?')
        ->call('publish')
        ->assertDispatched('rg-admin-toast', message: 'V1 published. It gets no traffic until you give it a share in Traffic.', tone: 'success')
        ->assertSee('Publish V2');

    expect(GameVersion::query()->sole()->number)->toBe(1);
});

it('holds Publish back while a round needs attention, saying which', function () {
    GameRoundOption::query()->where('position', 0)->first()->post->forceFill(['status' => 'hidden'])->save();

    Livewire::test(GameVersions::class, ['record' => $this->draft['game']->id])
        ->assertSee('1 of 2 rounds need attention in Content.')
        ->assertSee('Can’t publish yet')
        ->call('publish')
        ->assertDispatched('rg-admin-toast', tone: 'error');

    expect(GameVersion::query()->exists())->toBeFalse();
});

it('shares an audience out between versions and applies it', function () {
    $versions = Livewire::test(GameVersions::class, ['record' => $this->draft['game']->id]);
    $versions->call('publish');
    $versions->call('publish');
    [$v1, $v2] = GameVersion::query()->orderBy('number')->get()->all();

    Livewire::test(GameTraffic::class, ['record' => $this->draft['game']->id])
        ->assertSee('No traffic: nobody of this audience can be given a version.')
        ->call('addVersion', 'anonymous', $v1->id)
        ->call('addVersion', 'anonymous', $v2->id)
        ->call('splitEvenly', 'anonymous')
        ->assertSet('shares.anonymous.'.$v1->id, 50)
        ->set('shares.anonymous.'.$v2->id, 40)
        ->assertSee('Shares add up to 90%. Make them 100% to apply.')
        ->set('shares.anonymous.'.$v2->id, 50)
        ->call('apply', 'anonymous')
        ->assertDispatched('rg-admin-toast', message: 'Shares applied. A new period started.', tone: 'success')
        ->assertSee('Current');

    expect(GameTrafficPeriod::query()->where('audience', GameAudience::Anonymous)->sole()->shares())->toBe([$v1->id => 50, $v2->id => 50]);
});
