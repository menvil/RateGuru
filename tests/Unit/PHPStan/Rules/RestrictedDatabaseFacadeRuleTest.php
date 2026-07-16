<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\RestrictedDatabaseFacadeRule;

/** @extends RuleTestCase<RestrictedDatabaseFacadeRule> */
final class RestrictedDatabaseFacadeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RestrictedDatabaseFacadeRule([
            'Tests\\PHPStan\\Fixtures\\ApprovedDatabaseAccess',
        ]);
    }

    public function test_direct_database_access_and_controller_transactions_are_rejected(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Controllers/DatabaseAccess.php',
        ], [
            ['Direct DB::table() access is restricted to approved infrastructure classes.', 13],
            ['Direct DB::select() access is restricted to approved infrastructure classes.', 18],
            ['Direct DB::raw() access is restricted to approved infrastructure classes.', 23],
            ['HTTP controllers must not manage transactions; move DB::transaction() to an Action.', 28],
        ]);
    }

    public function test_transactions_outside_controllers_and_exact_allowlist_are_accepted(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Allowed/DatabaseAccess.php',
        ], []);
    }
}
