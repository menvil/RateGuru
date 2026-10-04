<?php

use App\Enums\UserStatus;

it('contains expected user statuses', function () {
    expect(UserStatus::Active->value)->toBe('active');
    expect(UserStatus::Limited->value)->toBe('limited');
    expect(UserStatus::Banned->value)->toBe('banned');
    expect(UserStatus::Shadowbanned->value)->toBe('shadowbanned');
    expect(UserStatus::Deleted->value)->toBe('deleted');
});

it('knows whether a status can create content', function () {
    expect(UserStatus::Active->canCreateContent())->toBeTrue();
    expect(UserStatus::Banned->canCreateContent())->toBeFalse();
    expect(UserStatus::Limited->canCreateContent())->toBeFalse();
    expect(UserStatus::Shadowbanned->canCreateContent())->toBeFalse();
});

/*
 * The lifecycle capability matrix — the product contract for what each
 * lifecycle state may do. Mirrors docs/architecture/user-lifecycle.md.
 *
 * PR-F: profile mutation is Active-only; authentication stays open for
 * every living state (sanctions restrict participation, not access) and
 * closed forever for the Deleted tombstone.
 */
it('enforces the lifecycle capability matrix', function (
    UserStatus $status,
    bool $create,
    bool $comment,
    bool $vote,
    bool $report,
    bool $follow,
    bool $beFollowed,
    bool $manageContent,
    bool $updateProfile,
    bool $authenticate,
    bool $panelEligible,
) {
    expect($status->canCreateContent())->toBe($create)
        ->and($status->canComment())->toBe($comment)
        ->and($status->canVote())->toBe($vote)
        ->and($status->canReport())->toBe($report)
        ->and($status->canFollow())->toBe($follow)
        ->and($status->canBeFollowed())->toBe($beFollowed)
        ->and($status->canManageContent())->toBe($manageContent)
        ->and($status->canUpdateProfile())->toBe($updateProfile)
        ->and($status->canAuthenticate())->toBe($authenticate)
        ->and($status->canAccessPrivilegedPanel())->toBe($panelEligible);
})->with([
    //                 status                    create comment vote  report follow beFllw manage profile auth  panel
    'active' => [UserStatus::Active, true, true, true, true, true, true, true, true, true, true],
    'limited' => [UserStatus::Limited, false, false, false, false, false, false, false, false, true, false],
    'banned' => [UserStatus::Banned, false, false, false, false, false, false, false, false, true, false],
    'shadowbanned' => [UserStatus::Shadowbanned, false, false, false, false, false, false, false, false, true, false],
    'deleted' => [UserStatus::Deleted, false, false, false, false, false, false, false, false, false, false],
]);

it('covers every enum case in the capability matrix', function () {
    // Guard for the matrix test above: adding a UserStatus case must force
    // an explicit matrix row for it.
    expect(UserStatus::cases())->toHaveCount(5);
});
