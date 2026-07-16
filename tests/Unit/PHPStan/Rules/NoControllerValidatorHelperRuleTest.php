<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\NoControllerValidatorHelperRule;

/** @extends RuleTestCase<NoControllerValidatorHelperRule> */
final class NoControllerValidatorHelperRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoControllerValidatorHelperRule;
    }

    public function test_validator_helper_is_rejected_in_controllers(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Controllers/InlineValidation.php',
        ], [
            [
                'HTTP controllers must validate input through a dedicated Form Request; do not use validator().',
                26,
            ],
        ]);
    }
}
