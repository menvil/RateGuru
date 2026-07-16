<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\NoPresentationModelMutationRule;

/** @extends RuleTestCase<NoPresentationModelMutationRule> */
final class NoPresentationModelMutationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoPresentationModelMutationRule;
    }

    public function test_eloquent_mutations_are_rejected_without_blocking_form_state_updates(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Presentation/ModelMutation.php',
        ], [
            ['Presentation classes must delegate Eloquent update() mutations to an Action.', 13],
            ['Presentation classes must delegate Eloquent updateOrCreate() mutations to an Action.', 40],
        ]);
    }
}
