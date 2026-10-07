<?php

use Illuminate\Support\Facades\File;

/*
 * A test that proves a root gate has to know whether it runs as root itself:
 * as root the gate lets it through, so the test skips. getmyuid() cannot tell —
 * it is the owner of the running PHP file, not the process — so as root over a
 * checkout another user owns, those tests ran and failed. testProcessIsRoot()
 * reads the effective uid.
 */

it('asks the process, not the PHP file, whether a test runs as root', function () {
    $rootCheck = '/getmyuid\(\)\s*[!=]==?\s*0\b|\b0\s*[!=]==?\s*getmyuid\(\)/';

    foreach (['if (getmyuid() === 0) {', 'skip(fn () => getmyuid() !== 0)', 'if (0 === getmyuid())'] as $check) {
        expect(preg_match($rootCheck, $check))->toBe(1, "not caught: {$check}");
    }

    foreach (['$owner = getmyuid();', "expect(\$stat['uid'])->toBe(getmyuid())", 'testProcessIsRoot()'] as $other) {
        expect(preg_match($rootCheck, $other))->toBe(0, "caught by mistake: {$other}");
    }

    $offenders = collect(File::allFiles(base_path('tests')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        // This file spells the forbidden check out, to prove it is caught.
        ->reject(fn ($file): bool => $file->getRealPath() === realpath(__FILE__))
        ->filter(fn ($file): bool => preg_match($rootCheck, phpSourceWithoutComments('tests/'.$file->getRelativePathname())) === 1)
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([], 'getmyuid() names the owner of the PHP file; ask testProcessIsRoot() whether the test runs as root');
});
