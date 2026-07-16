<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\NoInlineControllerValidationRule;

/** @extends RuleTestCase<NoInlineControllerValidationRule> */
final class NoInlineControllerValidationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoInlineControllerValidationRule;
    }

    public function test_request_validation_is_rejected_only_in_controllers(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Controllers/InlineValidation.php',
        ], [
            [
                'HTTP controllers must validate input through a dedicated Form Request; do not call Request::validate().',
                15,
            ],
        ]);
    }
}
