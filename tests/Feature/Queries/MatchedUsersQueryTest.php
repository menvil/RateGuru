<?php

use App\Models\User;
use App\Queries\Feed\MatchedUsersQuery;
use Illuminate\Support\Facades\DB;

it('treats like wildcards as literal user search characters', function (string $search, string $matchingUsername, string $otherUsername) {
    $matching = User::factory()->create([
        'username' => $matchingUsername,
        'name' => 'Matching User',
        'display_name' => null,
    ]);
    User::factory()->create([
        'username' => $otherUsername,
        'name' => 'Other User',
        'display_name' => null,
    ]);

    $users = app(MatchedUsersQuery::class)->search($search);

    expect($users->pluck('id')->all())->toBe([$matching->id]);
})->with([
    'percent' => ['%', 'chef_percent%', 'chef_percent0'],
    'underscore' => ['_', 'chef_under_score', 'chefXunderXscore'],
]);

it('does not n+1 query matched users\' avatar assets', function () {
    for ($i = 0; $i < 5; $i++) {
        User::factory()->withAvatar()->create(['username' => 'chef_'.fake()->unique()->userName()]);
    }

    $queryCount = 0;

    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $users = app(MatchedUsersQuery::class)->search('chef_');

    foreach ($users as $user) {
        // Exactly what the feed-page search results render.
        $user->resolved_avatar_url;
        $user->resolved_avatar_srcset;
    }

    // One query for the users, one for the avatarAsset batch, one for its
    // variants batch — not one per matched user.
    expect($queryCount)->toBeLessThanOrEqual(3);
});
