<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

/*
 * Project settings → Language ranking: the switch for the whole of it, the threads and OpenAI's checks, and the
 * numbers it runs on — saved with the rest of the draft, each number held to its range.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('shows the language ranking tab with what each setting does', function () {
    ProjectSettings::factory()->create();

    Livewire::withQueryParams(['tab' => 'languages'])->test(ProjectSettingsPage::class)
        ->assertSet('tab', 'languages')
        ->assertSee('Rank posts by language')
        ->assertSee('Comments in the visitor’s language first')
        ->assertSee('Check languages with OpenAI')
        ->assertSee('Fresh window for guests')
        ->assertSee('A strong post in another language every')
        ->assertSee('OpenAI checks per day')
        ->assertSet('data.language_ranking.enabled', false)
        ->assertSet('data.language_ranking.hit_every', 10);
});

it('saves what changed, leaving the rest as stored', function () {
    ProjectSettings::factory()->create(['language_ranking' => ['guest_window_days' => 3]]);

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.language_ranking.enabled', true)
        ->set('data.language_ranking.hit_every', '5')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProjectSettings::query()->find(1)->language_ranking)->toBe(['guest_window_days' => 3, 'enabled' => true, 'hit_every' => 5]);
});

it('refuses a number out of its range, naming the tab', function () {
    ProjectSettings::factory()->create();

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.language_ranking.hit_every', 99)
        ->call('save')
        ->assertHasErrors(['data.language_ranking.hit_every'])
        ->assertSee('Some settings can’t be saved yet: Language ranking.');

    expect(ProjectSettings::query()->find(1)->language_ranking)->toBeNull();
});
