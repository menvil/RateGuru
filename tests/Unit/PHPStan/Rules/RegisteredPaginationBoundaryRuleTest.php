<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\RegisteredPaginationBoundaryRule;

/** @extends RuleTestCase<RegisteredPaginationBoundaryRule> */
final class RegisteredPaginationBoundaryRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RegisteredPaginationBoundaryRule([
            [
                'class' => 'App\\Queries\\ArchitectureFixtures\\RegisteredPaginatedQuery',
                'method' => 'paginate',
                'uniqueOrder' => ['id'],
                'behaviorTests' => [__FILE__],
                'status' => 'approved',
            ],
        ]);
    }

    public function test_unregistered_query_object_pagination_is_rejected(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Queries/Pagination.php',
        ], [
            ['Paginated Query Object methods require an approved stable-pagination registry entry and behavior test.', 14],
        ]);
    }

    public function test_registered_query_object_pagination_is_accepted(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Allowed/RegisteredPagination.php',
        ], []);
    }

    public function test_pagination_outside_query_objects_is_rejected(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Services/Pagination.php',
        ], [
            ['Eloquent pagination must be owned by a registered Query Object.', 14],
        ]);
    }
}
