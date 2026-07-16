<?php

use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\ChangeLocaleRequest;
use App\Http\Requests\DeleteUserRequest;
use Illuminate\Support\Facades\Validator;

it('validates locale changes through a dedicated form request', function () {
    $request = new ChangeLocaleRequest;

    expect(Validator::make(['locale' => 'ru'], $request->rules())->passes())->toBeTrue()
        ->and(Validator::make(['locale' => 'de'], $request->rules())->fails())->toBeTrue()
        ->and(Validator::make(['locale' => ['ru']], $request->rules())->fails())->toBeTrue();
});

it('validates password reset link requests through a dedicated form request', function () {
    $request = new SendPasswordResetLinkRequest;

    expect(Validator::make(['email' => 'user@example.com'], $request->rules())->passes())->toBeTrue()
        ->and(Validator::make(['email' => ['user@example.com']], $request->rules())->fails())->toBeTrue()
        ->and(Validator::make(['email' => str_repeat('a', 256).'@example.com'], $request->rules())->fails())->toBeTrue();
});

it('validates new password requests through a dedicated form request', function () {
    $request = new ResetPasswordRequest;
    $valid = [
        'token' => 'reset-token',
        'email' => 'user@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];

    expect(Validator::make($valid, $request->rules())->passes())->toBeTrue()
        ->and(Validator::make([...$valid, 'token' => ['reset-token']], $request->rules())->fails())->toBeTrue()
        ->and(Validator::make([...$valid, 'password_confirmation' => 'different'], $request->rules())->fails())->toBeTrue();
});

it('validates registration through a dedicated form request', function () {
    $request = new RegisterUserRequest;
    $valid = [
        'name' => 'Test User',
        'email' => 'user@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];

    expect(Validator::make($valid, $request->rules())->passes())->toBeTrue()
        ->and(Validator::make([...$valid, 'name' => ['Test User']], $request->rules())->fails())->toBeTrue()
        ->and(Validator::make([...$valid, 'email' => str_repeat('a', 256).'@example.com'], $request->rules())->fails())->toBeTrue();
});

it('validates password confirmation through a dedicated form request', function () {
    $request = new ConfirmPasswordRequest;

    expect(Validator::make(['password' => 'password'], $request->rules())->passes())->toBeTrue()
        ->and(Validator::make(['password' => ['password']], $request->rules())->errors()->has('password'))->toBeTrue()
        ->and(Validator::make([], $request->rules())->errors()->has('password'))->toBeTrue();
});

it('validates password updates through a dedicated form request', function () {
    $request = new UpdatePasswordRequest;
    $valid = [
        'current_password' => 'current-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ];

    expect(Validator::make([...$valid, 'password' => ['new-password']], $request->rules())->errors()->has('password'))->toBeTrue()
        ->and(Validator::make([...$valid, 'password_confirmation' => 'different'], $request->rules())->errors()->has('password'))->toBeTrue();
});

it('validates account deletion through a dedicated form request', function () {
    $request = new DeleteUserRequest;

    expect(Validator::make(['password' => ['password']], $request->rules())->errors()->has('password'))->toBeTrue()
        ->and(Validator::make([], $request->rules())->errors()->has('password'))->toBeTrue();
});
