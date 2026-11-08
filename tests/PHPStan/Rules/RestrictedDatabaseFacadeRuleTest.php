<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\RestrictedDatabaseFacadeRule;

/** @extends RuleTestCase<RestrictedDatabaseFacadeRule> */
final class RestrictedDatabaseFacadeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RestrictedDatabaseFacadeRule([
            [
                'class' => 'Tests\\PHPStan\\Fixtures\\ApprovedDatabaseAccess',
                'methods' => ['table'],
                'reason' => 'Legacy fixture.',
                'behaviorTests' => [__FILE__],
                'status' => 'approved',
            ],
        ]);
    }

    public function test_direct_database_access_and_controller_transactions_are_rejected(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Controllers/DatabaseAccess.php',
            __DIR__.'/../Fixtures/Presentation/Transaction.php',
        ], [
            ['Direct DB::table() access is restricted to approved infrastructure classes.', 13],
            ['Direct DB::select() access is restricted to approved infrastructure classes.', 18],
            ['Direct DB::raw() access is restricted to approved infrastructure classes.', 23],
            ['Direct DB::cursor() access is restricted to approved infrastructure classes.', 28],
            ['Presentation classes must not manage transactions; move DB::transaction() to an Action.', 34],
            ['Direct DB::getPdo() access is restricted to approved infrastructure classes.', 39],
            ['Direct DB::getReadPdo() access is restricted to approved infrastructure classes.', 40],
            ['Direct DB::selectFromWriteConnection() access is restricted to approved infrastructure classes.', 41],
            ['Direct DB::getConnections() access is restricted to approved infrastructure classes.', 42],
            // A method is the same in any case.
            ['Direct DB::Cursor() access is restricted to approved infrastructure classes.', 47],
            ['Presentation classes must not manage transactions; move DB::transaction() to an Action.', 13],
            ['Presentation classes must not manage transactions; move DB::beginTransaction() to an Action.', 18],
            ['Presentation classes must not manage transactions; move DB::commit() to an Action.', 19],
            ['Presentation classes must not manage transactions; move DB::rollBack() to an Action.', 20],
            ['Presentation classes must not manage transactions; move DB::BeginTransaction() to an Action.', 25],
        ]);
    }

    public function test_transactions_outside_controllers_and_exact_allowlist_are_accepted(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Allowed/DatabaseAccess.php',
        ], []);
    }
}
