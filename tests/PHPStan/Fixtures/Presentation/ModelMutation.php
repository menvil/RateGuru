<?php

declare(strict_types=1);

namespace App\Livewire\Fixtures;

use App\Models\User;

final class ModelMutationComponent
{
    public function save(User $user): void
    {
        $user->update(['locale' => 'en']);
    }
}

final class FixtureForm
{
    /** @param array<string, mixed> $state */
    public function fill(array $state): void {}
}

final class FormStateComponent
{
    /** @param array<string, mixed> $state */
    public function hydrate(FixtureForm $form, array $state): void
    {
        $form->fill($state);
    }
}

namespace App\Filament\Fixtures;

use App\Models\ProjectSettings;

final class StaticModelMutationPage
{
    public function save(): void
    {
        ProjectSettings::updateOrCreate(['id' => 1], ['site_name' => 'RateGuru']);
    }
}
