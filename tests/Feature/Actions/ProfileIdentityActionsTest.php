<?php

use App\Actions\Profile\UpdateUserIdentityAction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

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

it('turns a username or address another account took after the form checked it into that field\'s error', function (string $field, string $value) {
    User::factory()->create([$field => $value]);
    $user = User::factory()->create(['username' => 'keeps_hers', 'email' => 'keeps@example.com']);

    try {
        app(UpdateUserIdentityAction::class)->execute($user, ['name' => $user->name, 'username' => 'keeps_hers', 'email' => 'keeps@example.com', $field => $value]);
        $this->fail('The update was not refused.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);
    }

    expect($user->fresh())->username->toBe('keeps_hers')->email->toBe('keeps@example.com');
})->with([
    'username' => ['username', 'taken_name'],
    'email' => ['email', 'taken@example.com'],
]);
