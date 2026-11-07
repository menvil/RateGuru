<?php

declare(strict_types=1);

namespace App\Livewire\Fixtures;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ModelMutationComponent
{
    public function save(User $user): void
    {
        $user->update(['locale' => 'en']);
    }
}

final class NullableModelMutationComponent
{
    public ?User $user = null;

    // A model that may not be loaded yet is written all the same once it is.
    public function save(): void
    {
        $this->user->update(['locale' => 'en']);
    }
}

final class ClassStringModelMutationComponent
{
    /** @param class-string<User> $users */
    public function save(string $users): void
    {
        $users::create(['locale' => 'en']);
    }
}

final class QuietAndFirstOrCreateMutationComponent
{
    public function save(User $user): void
    {
        $user->incrementQuietly('reports_count');
        User::createOrFirst(['email' => 'a@example.test']);
        User::query()->insertUsing(['email'], User::query()->select('email'));
    }
}

final class UnionReceiverMutationComponent
{
    /** @param User|Builder<User> $target */
    public function save(User|Builder $target): void
    {
        $target->update(['locale' => 'en']);
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
