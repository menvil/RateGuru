<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

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

    /** Larastan, as the real analysis has it: it types `$request->user()` and `User::where()`. */
    public static function getAdditionalConfigFiles(): array
    {
        return [dirname(__DIR__, 3).'/vendor/larastan/larastan/extension.neon'];
    }

    public function test_every_physical_user_deletion_is_rejected_but_other_model_deletes_are_not(): void
    {
        $this->analyse([
            __DIR__.'/../Fixtures/Users/PhysicalUserDeletion.php',
        ], [
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                17,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call forceDelete().',
                22,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call destroy().',
                27,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call forceDestroy().',
                32,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call deleteQuietly().',
                37,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call deleteOrFail().',
                38,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                44,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                49,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call forceDelete().',
                50,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call truncate().',
                51,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call destroy().',
                57,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                62,
            ],
            // Whichever a User|Builder<User> turns out to be; a Post|Builder<Post> at 92 is not.
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                86,
            ],
        ]);
    }
}
