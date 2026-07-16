<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\NoUnvalidatedControllerInputRule;

/** @extends RuleTestCase<NoUnvalidatedControllerInputRule> */
final class NoUnvalidatedControllerInputRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoUnvalidatedControllerInputRule;
    }

    public function test_request_input_access_is_type_aware(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Controllers/UnvalidatedInput.php',
        ], [
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::input().',
                19,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::string().',
                19,
            ],
        ]);
    }
}
