<?php

use App\Enums\TrafficStepValueMode;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameValue;
use App\Models\ProjectSettings;
use App\Models\TrafficSource;
use App\Models\TrafficStepValue;
use App\Models\TrafficVisit;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(3);
    liveGame($this->draft);
    $this->actingAs($this->draft['admin']);
});

it('lists every step of a play with what it is worth, the halfway round and the goal marked', function () {
    $this->get(GameResource::getUrl('value', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('Not worked out yet')
        ->assertSeeInOrder(['Opened', 'Started', 'Round 1', 'Round 2', 'Completed', 'Email', 'Account'])
        ->assertSee('Halfway')
        ->assertSee('Goal of this game');
});

it('sets a value by hand for a step, and removes it', function () {
    Livewire::test(GameValue::class, ['record' => $this->draft['game']->id])
        ->set('manual.started.value', '0.03')
        ->set('manual.started.mode', 'always')
        ->call('setValue', 'started')
        ->assertDispatched('rg-admin-toast', message: 'Value set. It counts from the next postback.');

    $value = TrafficStepValue::query()->sole();

    expect($value->value)->toBe('0.0300')->and($value->mode)->toBe(TrafficStepValueMode::Always);

    Livewire::test(GameValue::class, ['record' => $this->draft['game']->id])
        ->assertSee('Set by hand')
        ->call('clearValue', 'started');

    expect(TrafficStepValue::query()->count())->toBe(0);
});

it('shows the game’s paid traffic by source on its analytics, and compares its versions', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    TrafficVisit::factory()->count(3)->create(['source_id' => $source->id, 'game_id' => $this->draft['game']->id, 'arrived_at' => now()->subDay(), 'opened_at' => now()->subDay(), 'spend' => '0.10']);

    $this->get(GameResource::getUrl('analytics', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('Paid traffic · last 30 days')
        ->assertSee('PopAds main')
        ->assertSee('3 visits');
});
