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
            ['Query Objects are read-only; Eloquent save() is not allowed.', 15],
            ['Query Objects are read-only; Eloquent update() is not allowed.', 20],
            ['Query Objects are read-only; Eloquent sync() is not allowed.', 25],
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 30],
            ['Query Objects are read-only; database transactions are not allowed.', 35],
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 40],
            ['Query Objects are read-only; database transactions are not allowed.', 45],
            ['Query Objects are read-only; database transactions are not allowed.', 46],
            ['Query Objects are read-only; database transactions are not allowed.', 47],
            ['Query Objects are read-only; sharedLock() belongs in an Action transaction.', 52],
            // On the model too, which passes the lock on to its query.
            ['Query Objects are read-only; lockForUpdate() belongs in an Action transaction.', 57],
            // On a connection handed in, as on the facade, and in any case.
            ['Query Objects are read-only; database transactions are not allowed.', 62],
            ['Query Objects are read-only; database transactions are not allowed.', 67],
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
