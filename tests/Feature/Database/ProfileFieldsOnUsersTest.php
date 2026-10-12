<?php

use App\Enums\ProfileActivityVisibility;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('has profile fields on users table', function () {
    expect(Schema::hasColumns('users', [
        'display_name',
        'bio',
        'avatar_asset_id',
        'profile_website_url',
        'rating_activity_visibility',
    ]))->toBeTrue();

    expect(Schema::hasColumn('users', 'avatar_path'))->toBeFalse();
});

it('defaults rating_activity_visibility to private', function () {
    // Created without the factory, which sets the value itself, so the
    // default under test is the column's.
    $user = User::query()->create([
        'name' => 'Private Rater',
        'email' => 'private-rater@example.com',
        'password' => 'password',
    ]);

    expect($user->fresh()->rating_activity_visibility)->toBe(ProfileActivityVisibility::Private);
});
