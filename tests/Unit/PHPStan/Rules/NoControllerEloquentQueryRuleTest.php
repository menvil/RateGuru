<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\NoControllerEloquentQueryRule;

/** @extends RuleTestCase<NoControllerEloquentQueryRule> */
final class NoControllerEloquentQueryRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoControllerEloquentQueryRule;
    }

    public function test_controller_owned_eloquent_queries_are_rejected(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Controllers/EloquentReads.php',
        ], [
            ['HTTP controllers must delegate Eloquent queries to a Query Object; query() is not allowed here.', 15],
            ['HTTP controllers must delegate Eloquent queries to a Query Object; where() is not allowed here.', 15],
            ['HTTP controllers must delegate Eloquent queries to a Query Object; get() is not allowed here.', 15],
            ['HTTP controllers must delegate Eloquent queries to a Query Object; loadMissing() is not allowed here.', 20],
            ['HTTP controllers must delegate Eloquent queries to a Query Object; comments() is not allowed here.', 25],
            ['HTTP controllers must delegate Eloquent queries to a Query Object; latest() is not allowed here.', 25],
            ['HTTP controllers must delegate Eloquent queries to a Query Object; get() is not allowed here.', 25],
        ]);
    }

    public function test_route_models_and_query_object_calls_are_accepted(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Allowed/ControllerQueryBoundary.php',
        ], []);
    }
}
