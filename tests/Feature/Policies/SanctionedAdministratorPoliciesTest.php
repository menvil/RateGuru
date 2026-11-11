<?php

use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * Role AND lifecycle, in every administrator-only ability: a sanctioned
 * administrator keeps the role and loses the capability until restored, as
 * CategoryPolicy already decided. These abilities checked the role alone.
 */

/** @return array<string, Closure(User): bool> */
function administratorOnlyAbilities(): array
{
    return [
        'manage project settings' => fn (User $user): bool => Gate::forUser($user)->allows('manage-project-settings'),
        'view media diagnostics' => fn (User $user): bool => Gate::forUser($user)->allows('view-media-diagnostics'),
        'manage games' => fn (User $user): bool => Gate::forUser($user)->allows('manage-games'),
        'list rating groups' => fn (User $user): bool => $user->can('viewAny', RatingGroup::class),
        'view a rating group' => fn (User $user): bool => $user->can('view', RatingGroup::factory()->create()),
        'create a rating group' => fn (User $user): bool => $user->can('create', RatingGroup::class),
        'update a rating group' => fn (User $user): bool => $user->can('update', RatingGroup::factory()->create()),
        'list rating options' => fn (User $user): bool => $user->can('viewAny', RatingOption::class),
        'view a rating option' => fn (User $user): bool => $user->can('view', RatingOption::factory()->create()),
        'create a rating option' => fn (User $user): bool => $user->can('create', RatingOption::class),
        'update a rating option' => fn (User $user): bool => $user->can('update', RatingOption::factory()->create()),
        'delete a rating option' => fn (User $user): bool => $user->can('delete', RatingOption::factory()->create()),
        'delete a tag' => fn (User $user): bool => $user->can('delete', Tag::factory()->create()),
    ];
}

it('keeps each ability for an administrator in good standing', function (Closure $ability) {
    expect($ability(User::factory()->admin()->create()))->toBeTrue();
})->with(administratorOnlyAbilities());

it('refuses each ability to a sanctioned administrator', function (Closure $ability, string $sanction) {
    $admin = User::factory()->admin()->{$sanction}()->create();

    expect($admin->isAdmin())->toBeTrue('the fixture must still hold the role, or this proves nothing')
        ->and($ability($admin))->toBeFalse();
})->with(administratorOnlyAbilities())->with(['limited', 'banned', 'shadowbanned']);
