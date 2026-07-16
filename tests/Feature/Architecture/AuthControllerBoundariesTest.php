<?php

use App\Actions\Auth\AuthenticateUserAction;
use App\Actions\Auth\ConfirmPasswordAction;
use App\Actions\Auth\LogoutUserAction;
use App\Actions\Auth\RegisterUserAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\SendEmailVerificationNotificationAction;
use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Actions\Auth\UpdatePasswordAction;
use App\Actions\Auth\VerifyEmailAction;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Requests\Auth\LoginRequest;

it('delegates every mutating auth endpoint to a dedicated action', function () {
    $boundaries = [
        [AuthenticatedSessionController::class, 'store', AuthenticateUserAction::class],
        [AuthenticatedSessionController::class, 'destroy', LogoutUserAction::class],
        [ConfirmablePasswordController::class, 'store', ConfirmPasswordAction::class],
        [EmailVerificationNotificationController::class, 'store', SendEmailVerificationNotificationAction::class],
        [NewPasswordController::class, 'store', ResetPasswordAction::class],
        [PasswordController::class, 'update', UpdatePasswordAction::class],
        [PasswordResetLinkController::class, 'store', SendPasswordResetLinkAction::class],
        [RegisteredUserController::class, 'store', RegisterUserAction::class],
        [VerifyEmailController::class, '__invoke', VerifyEmailAction::class],
    ];

    foreach ($boundaries as [$controller, $method, $action]) {
        $parameterTypes = collect((new ReflectionMethod($controller, $method))->getParameters())
            ->map(static fn (ReflectionParameter $parameter): ?ReflectionType => $parameter->getType())
            ->filter(static fn (?ReflectionType $type): bool => $type instanceof ReflectionNamedType)
            ->map(static fn (ReflectionType $type): string => $type->getName());

        $this->assertContains(
            $action,
            $parameterTypes->all(),
            "{$controller}::{$method} must delegate to {$action}",
        );
    }
});

it('keeps the login form request limited to authorization and validation rules', function () {
    $declaredPublicMethods = collect((new ReflectionClass(LoginRequest::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === LoginRequest::class)
        ->map(static fn (ReflectionMethod $method): string => $method->getName())
        ->sort()
        ->values()
        ->all();

    expect($declaredPublicMethods)->toBe(['authorize', 'rules']);
});
