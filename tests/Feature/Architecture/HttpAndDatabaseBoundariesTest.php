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

it('keeps inline validation out of http controllers', function () {
    $violations = [];

    foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
        $source = $file->getContents();

        if (preg_match('/\$\w+->(?:validate|validateWithBag)\s*\(|Validator::make\s*\(|\bvalidator\s*\(/', $source) === 1) {
            $violations[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($violations)->toBe([]);
});

it('keeps unvalidated input access out of http controllers', function () {
    $violations = [];

    foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
        $source = $file->getContents();

        if (preg_match('/\$\w+->(?:all|boolean|file|get|input|integer|only|query|string)\s*\(/', $source) === 1) {
            $violations[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($violations)->toBe([]);
});

it('keeps direct query builder access behind explicit technical exceptions', function () {
    $legacyMigrator = 'app/Services/Rating/LegacyRatingVoteMigrator.php';
    $violations = [];

    foreach (File::allFiles(app_path()) as $file) {
        $path = str_replace(base_path().'/', '', $file->getPathname());
        $source = $file->getContents();

        if ($path === $legacyMigrator) {
            $source = preg_replace('/DB::table\s*\(\s*\$table\s*\)/', '', $source, 1, $allowedCount);

            expect($allowedCount)->toBe(1, 'The legacy migrator may read exactly one dynamic legacy table.');
        }

        if (preg_match('/DB::(?:table|query|select|selectOne|scalar|insert|update|delete|statement|unprepared)\s*\(/', $source) === 1) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
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

it('limits raw sql expressions to reviewed eloquent query boundaries', function () {
    $allowedFiles = [
        'app/Models/Comment.php',
        'app/Queries/Feed/FeedQuery.php',
        'app/Queries/Feed/MatchedUsersQuery.php',
        'app/Services/PostVoteResultService.php',
        'app/Support/Rating/RatingVotingStateLoader.php',
    ];
    $violations = [];

    foreach (File::allFiles(app_path()) as $file) {
        $path = str_replace(base_path().'/', '', $file->getPathname());

        if (in_array($path, $allowedFiles, true)) {
            continue;
        }

        if (preg_match('/->(?:whereRaw|orWhereRaw|selectRaw|addSelectRaw|orderByRaw|groupByRaw|havingRaw|fromRaw)\s*\(|DB::raw\s*\(/', $file->getContents()) === 1) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});
