<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows only administrators to manage project settings', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();
    $user = User::factory()->create();

    expect(Gate::forUser($admin)->allows('manage-project-settings'))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('manage-project-settings'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('manage-project-settings'))->toBeFalse();
});
