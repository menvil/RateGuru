<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\RestrictedRawQueryRule;

/** @extends RuleTestCase<RestrictedRawQueryRule> */
final class RestrictedRawQueryRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RestrictedRawQueryRule([
            [
                'class' => 'Tests\\PHPStan\\Fixtures\\ApprovedRawQuery',
                'methods' => ['whereRaw'],
                'reason' => 'Literal fixture.',
                'bindings' => 'literal_only',
                'behaviorTests' => [__FILE__],
                'status' => 'approved',
            ],
            [
                'class' => 'Tests\\PHPStan\\Fixtures\\MissingRawBindings',
                'methods' => ['whereRaw'],
                'reason' => 'Bound fixture.',
                'bindings' => 'required',
                'behaviorTests' => [__FILE__],
                'status' => 'approved',
            ],
        ]);
    }

    public function test_approved_bound_expressions_require_a_bindings_argument(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Database/MissingRawBindings.php',
        ], [
            ['This approved raw SQL call requires a separate bindings argument.', 13],
        ]);
    }

    public function test_raw_builder_methods_are_rejected_outside_exact_allowlist(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Database/RawQueries.php',
        ], [
            ['Raw query method whereRaw() is restricted to approved Query Objects.', 14],
            ['Raw query method orderByRaw() is restricted to approved Query Objects.', 16],
            ['Raw query method whereRaw() is restricted to approved Query Objects.', 16],
        ]);
    }

    public function test_eloquent_relationships_and_approved_raw_queries_are_accepted(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Allowed/EloquentQueries.php',
            __DIR__.'/../Fixtures/Allowed/RawQueries.php',
        ], []);
    }
}
