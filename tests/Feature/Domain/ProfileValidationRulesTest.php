<?php

use App\Support\Profile\ProfileValidationRules;
use Illuminate\Support\Facades\Validator;

it('validates display name length', function () {
    $rules = app(ProfileValidationRules::class)->rules();

    $validator = Validator::make([
        'display_name' => str_repeat('A', 120),
    ], $rules);

    expect($validator->fails())->toBeTrue();
});

it('accepts valid display name', function () {
    $rules = app(ProfileValidationRules::class)->rules();

    $validator = Validator::make([
        'display_name' => 'Ivan Moroz',
    ], $rules);

    expect($validator->fails())->toBeFalse();
});

it('allows nullable display name', function () {
    $rules = app(ProfileValidationRules::class)->rules();

    $validator = Validator::make(['display_name' => null], $rules);

    expect($validator->fails())->toBeFalse();
});

it('validates profile website url', function () {
    $rules = app(ProfileValidationRules::class)->rules();

    $validator = Validator::make([
        'profile_website_url' => 'not-a-url',
    ], $rules);

    expect($validator->fails())->toBeTrue();
});

it('accepts valid profile website url', function () {
    $rules = app(ProfileValidationRules::class)->rules();

    $validator = Validator::make([
        'profile_website_url' => 'https://example.com',
    ], $rules);

    expect($validator->fails())->toBeFalse();
});

it('validates bio max length', function () {
    $rules = app(ProfileValidationRules::class)->rules();

    $validator = Validator::make([
        'bio' => str_repeat('A', 600),
    ], $rules);

    expect($validator->fails())->toBeTrue();
});

it('validates rating activity visibility allowed values', function () {
    $rules = app(ProfileValidationRules::class)->rules();

    $validator = Validator::make([
        'rating_activity_visibility' => 'followers_only',
    ], $rules);

    expect($validator->fails())->toBeTrue();
});

it('accepts private and public visibility values', function () {
    $rules = app(ProfileValidationRules::class)->rules();

    foreach (['private', 'public'] as $value) {
        $validator = Validator::make([
            'rating_activity_visibility' => $value,
        ], $rules);

        expect($validator->fails())->toBeFalse();
    }
});
