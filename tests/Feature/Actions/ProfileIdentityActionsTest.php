<?php

use App\Actions\Profile\DeleteUserAccountAction;
use App\Actions\Profile\UpdateUserIdentityAction;
use App\Enums\UserStatus;
use App\Models\User;

it('updates identity fields and clears verification only when email changes', function () {
    $user = User::factory()->create([
        'name' => 'Original Name',
        'username' => 'original_name',
    ]);
    $verifiedAt = $user->email_verified_at;

    app(UpdateUserIdentityAction::class)->execute($user, [
        'name' => 'Renamed User',
        'username' => 'renamed_user',
        'email' => $user->email,
    ]);

    expect($user->refresh()->email_verified_at?->equalTo($verifiedAt))->toBeTrue();

    app(UpdateUserIdentityAction::class)->execute($user, [
        'name' => 'Renamed User',
        'username' => 'renamed_user',
        'email' => 'renamed@example.com',
    ]);

    expect($user->refresh())
        ->name->toBe('Renamed User')
        ->username->toBe('renamed_user')
        ->email->toBe('renamed@example.com')
        ->email_verified_at->toBeNull();
});

it('logs out and tombstones the account through the deletion action', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(DeleteUserAccountAction::class)->execute($user);

    $this->assertGuest();

    // Deletion anonymizes into an irreversible tombstone; the row remains
    // so all community contribution keeps a valid author reference.
    $fresh = $user->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(UserStatus::Deleted)
        ->and($fresh->anonymized_at)->not->toBeNull();
});
