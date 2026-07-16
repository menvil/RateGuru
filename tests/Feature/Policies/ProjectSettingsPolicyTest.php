<?php

use App\Models\User;
use App\Policies\ProjectSettingsPolicy;
use Illuminate\Support\Facades\Gate;

it('registers project settings management as a global gate ability', function () {
    $policy = app(ProjectSettingsPolicy::class);

    expect(method_exists($policy, 'manage'))->toBeTrue();
});

it('allows only administrators to manage project settings', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();
    $user = User::factory()->create();

    expect(Gate::forUser($admin)->allows('manage-project-settings'))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('manage-project-settings'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('manage-project-settings'))->toBeFalse();
});
