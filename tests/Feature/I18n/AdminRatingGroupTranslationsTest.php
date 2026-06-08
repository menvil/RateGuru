<?php

use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Models\RatingGroup;
use App\Models\User;
use Livewire\Livewire;

it('admin can save rating group translations', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditRatingGroup::class, ['record' => $group->getKey()])
        ->set('data.label_translations.ru', 'Источник')
        ->call('save')
        ->assertHasNoErrors();

    expect($group->fresh()->label_translations['ru'])->toBe('Источник');
});
