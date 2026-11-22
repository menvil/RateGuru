<?php

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\ChangeGameVersionAction;
use App\Actions\Games\CreateContentSetAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameAudience;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameTraffic;
use App\Filament\Resources\Games\Pages\GameVersions;
use App\Models\GameRoundOption;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use Illuminate\Support\Facades\DB;
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

it('applies whole-number shares as typed, and says before Apply that a fraction is not one', function () {
    $publish = fn () => app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game'], $this->draft['set']->id, $this->draft['flow']->id);
    [$v1, $v2] = [$publish(), $publish()];

    $page = Livewire::test(GameTraffic::class, ['record' => $this->draft['game']->id])
        ->call('addVersion', 'anonymous', $v1->id)
        ->call('addVersion', 'anonymous', $v2->id)
        ->set('shares.anonymous.'.$v1->id, '050')
        ->set('shares.anonymous.'.$v2->id, '50')
        ->assertSee('Shares add up to 100%.')
        ->call('apply', 'anonymous')
        ->assertDispatched('rg-admin-toast', message: 'Shares applied. A new period started.', tone: 'success');

    expect(GameTrafficPeriod::query()->where('audience', GameAudience::Anonymous)->sole()->shares())->toBe([$v1->id => 50, $v2->id => 50]);

    $page->set('shares.anonymous.'.$v1->id, '12.5')
        ->assertSee('Each share is a whole number from 1 to 100.');
});

it('applies nothing over shares changed since the page was drawn, and draws them again', function () {
    $publish = fn (?int $takeOver = null) => app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $this->draft['flow']->id, $takeOver);
    [$v1, $v2] = [$publish(), $publish()];
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $this->draft['game'], GameAudience::Anonymous, [$v1->id => 100]);

    $page = Livewire::test(GameTraffic::class, ['record' => $this->draft['game']->id]);

    // In another tab: V3 is published, taking over V1's share.
    $v3 = $publish($v1->id);

    $page->call('addVersion', 'anonymous', $v2->id)
        ->set('shares.anonymous.'.$v1->id, 50)
        ->set('shares.anonymous.'.$v2->id, 50)
        ->call('apply', 'anonymous')
        ->assertDispatched('rg-admin-toast', message: 'These shares changed after you opened the page, so nothing was applied. They now show as they are.', tone: 'error')
        ->assertSet('shares.anonymous', [$v3->id => 100]);

    expect(GameTrafficPeriod::query()->where('audience', GameAudience::Anonymous)->whereNull('ended_at')->sole()->shares())->toBe([$v3->id => 100]);
});

it('applies one audience\'s shares and leaves the other\'s unsaved ones as they are', function () {
    $publish = fn () => app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    [$v1, $v2] = [$publish(), $publish()];
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $this->draft['game'], GameAudience::Authenticated, [$v1->id => 100]);

    $page = Livewire::test(GameTraffic::class, ['record' => $this->draft['game']->id])
        ->call('addVersion', 'anonymous', $v1->id)
        ->set('shares.anonymous.'.$v1->id, 100)
        ->call('addVersion', 'authenticated', $v2->id)
        ->set('shares.authenticated.'.$v1->id, 40)
        ->set('shares.authenticated.'.$v2->id, 60)
        ->call('apply', 'anonymous')
        ->assertDispatched('rg-admin-toast', message: 'Shares applied. A new period started.', tone: 'success')
        ->assertSet('shares.authenticated', [$v1->id => 40, $v2->id => 60]);

    expect(collect($page->viewData('groups'))->firstWhere('audience', 'authenticated')['changed'])->toBeTrue();

    // In another tab: the logged-in shares are applied anew, so this page's are out of date.
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $this->draft['game'], GameAudience::Authenticated, [$v2->id => 100]);

    $page->call('apply', 'authenticated')
        ->assertDispatched('rg-admin-toast', message: 'These shares changed after you opened the page, so nothing was applied. They now show as they are.', tone: 'error')
        ->assertSet('shares.authenticated', [$v2->id => 100]);
});

it('reads every content set of the Versions tab in the same few queries, however many there are', function () {
    $queries = function (): int {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        Livewire::test(GameVersions::class, ['record' => $this->draft['game']->id]);

        return $count;
    };

    $one = $queries();

    foreach (range(1, 3) as $copy) {
        app(CreateContentSetAction::class)->handle($this->draft['admin'], $this->draft['game'], 'Copy '.$copy, $this->draft['set']->id);
    }

    expect($queries() - $one)->toBe(0);
});

it('says the versions are all archived, rather than that there are none', function () {
    $version = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game'], $this->draft['set']->id, $this->draft['flow']->id);
    app(ChangeGameVersionAction::class)->retire($this->draft['admin'], $version);

    Livewire::test(GameVersions::class, ['record' => $this->draft['game']->id])
        ->assertSee('No active versions')
        ->assertSee('1 archived. Turn on Show archived to see it.')
        ->assertDontSee('No versions yet')
        ->set('showRetired', true)
        ->assertDontSee('No active versions')
        ->assertSee('wire:key="version-'.$version->id.'"', false);
});
