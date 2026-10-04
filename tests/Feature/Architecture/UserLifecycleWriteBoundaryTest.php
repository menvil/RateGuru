<?php

use Illuminate\Support\Facades\File;

/*
 * Sanctioned-lifecycle contract: user lifecycle mutation may only happen
 * inside explicit boundaries — account anonymization and the logged
 * moderation transition actions. Anything else (a Filament form, a
 * service "just fixing" a status) would bypass the transition matrix,
 * pair locking, reason capture and the ModerationLog.
 *
 * The write shape is `'status' => UserStatus::…` inside forceFill/update
 * arrays; read-side comparisons use `where('status', UserStatus::…)` and
 * do not match.
 */
it('keeps user status writes inside sanctioned lifecycle boundaries', function () {
    $allowlist = [
        'app/Actions/Profile/AnonymizeUserAccountAction.php',
        'app/Actions/Moderation/Concerns/ExecutesUserStatusTransition.php',
        // Bootstrap boundary: creates a NEW Active admin, never transitions
        // an existing account.
        'app/Console/Commands/CreateAdminUserCommand.php',
    ];

    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.php'))
        ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
        ->reject(fn (string $path) => in_array($path, $allowlist, true))
        ->filter(fn (string $path) => preg_match(
            // (?!class) excludes the enum cast declaration on the model.
            "/'status'\s*=>\s*UserStatus::(?!class)/",
            (string) file_get_contents(base_path($path)),
        ) === 1)
        ->values();

    expect($offenders->all())->toBe([]);
});
