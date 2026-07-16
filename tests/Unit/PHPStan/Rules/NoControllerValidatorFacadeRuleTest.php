<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\NoControllerValidatorFacadeRule;

/** @extends RuleTestCase<NoControllerValidatorFacadeRule> */
final class NoControllerValidatorFacadeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoControllerValidatorFacadeRule;
    }

    public function test_validator_facade_is_rejected_in_controllers(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Controllers/InlineValidation.php',
        ], [
            [
                'HTTP controllers must validate input through a dedicated Form Request; do not use the Validator facade.',
                21,
            ],
        ]);
    }
}
