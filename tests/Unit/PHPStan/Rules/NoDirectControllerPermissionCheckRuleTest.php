<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\NoDirectControllerPermissionCheckRule;

/** @extends RuleTestCase<NoDirectControllerPermissionCheckRule> */
final class NoDirectControllerPermissionCheckRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoDirectControllerPermissionCheckRule;
    }

    public function test_direct_permission_checks_are_rejected_only_in_controllers(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Controllers/DirectPermissionCheck.php',
        ], [
            [
                'HTTP controllers must authorize through Gate or policies; do not call isAdmin() directly.',
                13,
            ],
        ]);
    }
}
