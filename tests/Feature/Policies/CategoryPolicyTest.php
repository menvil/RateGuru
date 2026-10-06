<?php

use App\Models\Category;
use App\Models\User;

/*
 * Category administration is role AND lifecycle, the same pair every other
 * privileged policy here applies.
 *
 * It used to be role alone. That made the lifecycle invisible to this policy, and
 * the consequence was not theoretical: DeleteCategoryAction re-reads and locks
 * the actor precisely because a sanction can commit between the pre-check and the
 * write — and a sanction changes STATUS, which the policy then ignored. The
 * re-authorization could only ever catch a role change, which is the less likely
 * of the two.
 */

/** @return list<string> */
function categoryAbilities(): array
{
    return ['viewAny', 'view', 'create', 'update', 'delete'];
}

it('lets an active administrator administer categories', function (string $ability) {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();

    expect($admin->can($ability, $ability === 'create' || $ability === 'viewAny' ? Category::class : $category))
        ->toBeTrue("an active admin must be allowed to {$ability}");
})->with(categoryAbilities());

it('refuses a sanctioned administrator every category ability', function (string $ability, string $sanction) {
    // The role is intact; the lifecycle is not. Both halves have to be checked,
    // or a banned administrator keeps the whole of category management.
    $admin = User::factory()->admin()->{$sanction}()->create();
    $category = Category::factory()->create();

    expect($admin->canAccessPrivilegedPanel())->toBeFalse('the fixture must actually be sanctioned')
        ->and($admin->isAdmin())->toBeTrue('and must still hold the role, or this proves nothing');

    expect($admin->can($ability, $ability === 'create' || $ability === 'viewAny' ? Category::class : $category))
        ->toBeFalse("a {$sanction} admin must not be allowed to {$ability}");
})->with(categoryAbilities())->with(['banned', 'limited', 'shadowbanned']);

it('refuses a tombstoned administrator every category ability', function (string $ability) {
    $admin = User::factory()->admin()->tombstoned()->create();
    $category = Category::factory()->create();

    expect($admin->can($ability, $ability === 'create' || $ability === 'viewAny' ? Category::class : $category))
        ->toBeFalse("a tombstoned admin must not be allowed to {$ability}");
})->with(categoryAbilities());

it('refuses a non-administrator every category ability', function (string $ability) {
    // The other half of the pair: lifecycle-eligible, wrong role.
    $member = User::factory()->create();
    $category = Category::factory()->create();

    expect($member->canAccessPrivilegedPanel())->toBeTrue()
        ->and($member->can($ability, $ability === 'create' || $ability === 'viewAny' ? Category::class : $category))
        ->toBeFalse("an ordinary member must not be allowed to {$ability}");
})->with(categoryAbilities());
