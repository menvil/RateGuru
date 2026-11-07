<?php

declare(strict_types=1);

/*
 * The repository's own architecture errors are never suppressible, as
 * docs/architecture/http-and-database-boundaries.md has it: each is built
 * non-ignorable, so no baseline, ignoreErrors entry or inline
 * @phpstan-ignore can hide one.
 */

it('builds every rateguru error non-ignorable', function () {
    $rules = glob(dirname(__DIR__, 2).'/tools/phpstan/src/Rules/*.php');

    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        preg_match_all("/->identifier\('rateguru\.[^']+'\)\s*->(\w+)\(/", (string) file_get_contents($rule), $next);

        expect($next[1])->not->toBeEmpty("{$rule} builds no rateguru error")
            ->each->toBe('nonIgnorable');
    }
});
