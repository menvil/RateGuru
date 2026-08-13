<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use RateGuru\PHPStan\Rules\NoPhysicalUserDeletionRule;

/** @extends RuleTestCase<NoPhysicalUserDeletionRule> */
final class NoPhysicalUserDeletionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoPhysicalUserDeletionRule;
    }

    public function test_physical_user_deletion_is_rejected_but_other_model_deletes_are_not(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Users/PhysicalUserDeletion.php',
        ], [
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                15,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call forceDelete().',
                20,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call destroy().',
                25,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call forceDestroy().',
                30,
            ],
        ]);
    }
}
