<?php

use App\Enums\UserRole;

it('contains expected user roles', function () {
    expect(UserRole::User->value)->toBe('user');
    expect(UserRole::Persona->value)->toBe('persona');
    expect(UserRole::Moderator->value)->toBe('moderator');
    expect(UserRole::Admin->value)->toBe('admin');
});

it('has exactly four roles, in order', function () {
    expect(UserRole::cases())->toBe([UserRole::User, UserRole::Persona, UserRole::Moderator, UserRole::Admin]);
});

it('labels every role', function (UserRole $role, string $label) {
    expect($role->label())->toBe($label);
})->with([
    'user' => [UserRole::User, 'User'],
    'persona' => [UserRole::Persona, 'Persona'],
    'moderator' => [UserRole::Moderator, 'Moderator'],
    'admin' => [UserRole::Admin, 'Admin'],
]);

/*
 * Staff is Admin and Moderator by name, never "anything but a User": a
 * Persona administers nothing and is exempt from nothing staff are.
 */
it('counts only administrators and moderators as staff', function (UserRole $role, bool $staff) {
    expect($role->isStaff())->toBe($staff);
})->with([
    'user' => [UserRole::User, false],
    'persona' => [UserRole::Persona, false],
    'moderator' => [UserRole::Moderator, true],
    'admin' => [UserRole::Admin, true],
]);
