<?php

use App\Actions\Profile\DeleteUserAccountAction;
use App\Actions\Profile\UpdateUserIdentityAction;
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

it('logs out and deletes the account through the deletion action', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(DeleteUserAccountAction::class)->execute($user);

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});
