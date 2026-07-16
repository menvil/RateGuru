<?php

use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Locale\ChangeLocaleController;
use App\Http\Controllers\ProfileController;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\ChangeLocaleRequest;
use App\Http\Requests\DeleteUserRequest;
use Illuminate\Support\Facades\File;

it('uses dedicated form requests for controller validation', function () {
    $actions = [
        [ChangeLocaleController::class, '__invoke', ChangeLocaleRequest::class],
        [PasswordResetLinkController::class, 'store', SendPasswordResetLinkRequest::class],
        [NewPasswordController::class, 'store', ResetPasswordRequest::class],
        [RegisteredUserController::class, 'store', RegisterUserRequest::class],
        [ConfirmablePasswordController::class, 'store', ConfirmPasswordRequest::class],
        [PasswordController::class, 'update', UpdatePasswordRequest::class],
        [ProfileController::class, 'destroy', DeleteUserRequest::class],
    ];

    foreach ($actions as [$controller, $method, $expectedRequest]) {
        $parameter = (new ReflectionMethod($controller, $method))->getParameters()[0];

        expect($parameter->getType()?->getName())
            ->toBe($expectedRequest, "{$controller}::{$method} must use {$expectedRequest}");
    }
});

it('keeps the base query builder out of application dependencies', function () {
    $violations = [];

    foreach (File::allFiles(app_path()) as $file) {
        if (str_contains($file->getContents(), 'Illuminate\\Database\\Query\\Builder')) {
            $violations[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($violations)->toBe([]);
});
