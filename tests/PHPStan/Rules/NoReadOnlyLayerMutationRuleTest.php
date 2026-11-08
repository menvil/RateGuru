<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\NoReadOnlyLayerMutationRule;

/** @extends RuleTestCase<NoReadOnlyLayerMutationRule> */
final class NoReadOnlyLayerMutationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoReadOnlyLayerMutationRule;
    }

    public function test_queries_and_policies_cannot_mutate_or_lock_models(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Queries/MutatingQuery.php',
            __DIR__.'/../Fixtures/Policies/MutatingPolicy.php',
        ], [
            ['Query Objects are read-only; Eloquent save() is not allowed.', 14],
            ['Query Objects are read-only; Eloquent update() is not allowed.', 19],
            ['Query Objects are read-only; Eloquent sync() is not allowed.', 24],
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 29],
            ['Query Objects are read-only; database transactions are not allowed.', 34],
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 39],
            ['Query Objects are read-only; database transactions are not allowed.', 44],
            ['Query Objects are read-only; database transactions are not allowed.', 45],
            ['Query Objects are read-only; database transactions are not allowed.', 46],
            ['Query Objects are read-only; sharedLock() belongs in an Action transaction.', 51],
            // On the model too, which passes the lock on to its query.
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 56],
            // On a connection handed in, as on the facade, and in any case.
            ['Query Objects are read-only; database transactions are not allowed.', 61],
            ['Query Objects are read-only; database transactions are not allowed.', 66],
            ['Policies are read-only; Eloquent update() is not allowed.', 13],
        ]);
    }

    public function test_normal_eloquent_reads_remain_allowed(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Allowed/ReadOnlyQuery.php',
        ], []);
    }
}
