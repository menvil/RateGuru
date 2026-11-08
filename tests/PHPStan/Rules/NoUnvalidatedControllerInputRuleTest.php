<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

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
                21,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::string().',
                22,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not use Request array access.',
                23,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not use Request magic properties.',
                24,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::except().',
                25,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::filled().',
                26,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::input().',
                27,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call request() with a key.',
                28,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::cookie().',
                29,
            ],
            // Laravel's global alias of the facade.
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::input().',
                30,
            ],
            [
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::cookie().',
                31,
            ],
        ]);
    }
}
