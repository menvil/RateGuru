<?php

use App\Models\User;
use App\Queries\Feed\MatchedUsersQuery;

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
