<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

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

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/inline-validation.neon'];
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

    public function test_validation_through_the_controller_trait_is_rejected(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Controllers/TraitValidation.php',
        ], [
            [
                'HTTP controllers must validate input through a dedicated Form Request; do not call ValidatesRequests::validate().',
                16,
            ],
        ]);
    }
}
