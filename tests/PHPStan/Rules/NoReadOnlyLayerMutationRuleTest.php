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
            ['Query Objects are read-only; Eloquent save() is not allowed.', 17],
            ['Query Objects are read-only; Eloquent update() is not allowed.', 22],
            ['Query Objects are read-only; Eloquent sync() is not allowed.', 27],
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 32],
            ['Query Objects are read-only; database transactions are not allowed.', 37],
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 42],
            ['Query Objects are read-only; database transactions are not allowed.', 47],
            ['Query Objects are read-only; database transactions are not allowed.', 48],
            ['Query Objects are read-only; database transactions are not allowed.', 49],
            ['Query Objects are read-only; sharedLock() belongs in an Action transaction.', 54],
            // On the model too, which passes the lock on to its query.
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 59],
            // On a connection handed in, as on the facade, and in any case.
            ['Query Objects are read-only; database transactions are not allowed.', 64],
            ['Query Objects are read-only; database transactions are not allowed.', 69],
            // A receiver that may be null, or one of two types.
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 74],
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 80],
            ['Query Objects are read-only; database transactions are not allowed.', 85],
            ['Query Objects are read-only; database transactions are not allowed.', 90],
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
