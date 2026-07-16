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
            'Tests\\PHPStan\\Fixtures\\ApprovedRawQuery',
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
