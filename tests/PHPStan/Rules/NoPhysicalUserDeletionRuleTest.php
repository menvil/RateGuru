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
                16,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call forceDelete().',
                21,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call destroy().',
                26,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call forceDestroy().',
                31,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call deleteQuietly().',
                36,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call deleteOrFail().',
                37,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                43,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                48,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call forceDelete().',
                49,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call truncate().',
                50,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call destroy().',
                56,
            ],
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                61,
            ],
            // Whichever a User|Builder<User> turns out to be; a Post|Builder<Post> at 91 is not.
            [
                'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call delete().',
                85,
            ],
        ]);
    }
}
