<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Queries\Users\ModerationUsersQuery;

/**
 * The people moderation lists, a tab at a time: what each holds, the role,
 * the suspicious and the search narrowing it, the reports on each person's
 * content, and an order that keeps everyone in their place across pages.
 */
function moderationUsersPage(string $tab = 'all', string $search = '', ?UserRole $role = null, bool $suspicious = false, int $page = 1): array
{
    return app(ModerationUsersQuery::class)->paginate($tab, $search, $role, $suspicious, $page)->pluck('id')->all();
}

it('lists each status on its own tab, and the trusted among the active, newest first', function () {
    $trusted = User::factory()->create(['trust_level' => 10, 'created_at' => now()->subDays(5)]);
    $reviewed = User::factory()->create(['trust_level' => 0, 'created_at' => now()->subDays(4)]);
    $limited = User::factory()->create(['status' => UserStatus::Limited, 'trust_level' => 10, 'created_at' => now()->subDays(3)]);
    $banned = User::factory()->banned()->create(['created_at' => now()->subDays(2)]);
    $deleted = User::factory()->tombstoned()->create(['created_at' => now()->subDay()]);

    expect(moderationUsersPage())->toBe([$deleted->id, $banned->id, $limited->id, $reviewed->id, $trusted->id])
        ->and(moderationUsersPage('active'))->toBe([$reviewed->id, $trusted->id])
        ->and(moderationUsersPage('limited'))->toBe([$limited->id])
        ->and(moderationUsersPage('banned'))->toBe([$banned->id])
        ->and(moderationUsersPage('deleted'))->toBe([$deleted->id])
        ->and(moderationUsersPage('trusted'))->toBe([$trusted->id])
        ->and(app(ModerationUsersQuery::class)->counts())->toMatchArray(['all' => 5, 'active' => 2, 'trusted' => 1])
        ->and(app(ModerationUsersQuery::class)->restricted())->toBe(2);
});

it('narrows to a role, to the suspicious, and by username, name or email', function () {
    // Every field searched set, so no generated name, username or email matches by chance.
    $moderator = User::factory()->moderator()->create(['username' => 'georgi_d', 'name' => 'Desk Moderator', 'email' => 'desk@example.com']);
    $shadowbanned = User::factory()->shadowbanned()->create(['name' => 'Elena Popova', 'username' => 'elena_p', 'email' => 'elena@example.com']);
    $reported = User::factory()->create(['email' => 'sales@pet-deals24.top', 'name' => 'Pet Seller', 'username' => 'pet_seller']);
    Post::factory()->published()->for($reported)->create(['reports_count' => 2]);
    $clean = User::factory()->create(['name' => 'Plain Member', 'username' => 'plain_member', 'email' => 'member@example.com']);

    expect(moderationUsersPage(role: UserRole::Moderator))->toBe([$moderator->id])
        ->and(moderationUsersPage(suspicious: true))->toEqualCanonicalizing([$shadowbanned->id, $reported->id])
        ->and(moderationUsersPage(search: 'GEORGI'))->toBe([$moderator->id])
        ->and(moderationUsersPage(search: 'popova'))->toBe([$shadowbanned->id])
        ->and(moderationUsersPage(search: 'deals24'))->toBe([$reported->id])
        ->and(moderationUsersPage(search: 'georgi%'))->toBe([])
        ->and(app(ModerationUsersQuery::class)->staff())->toBe(1)
        ->and(moderationUsersPage())->toContain($clean->id);
});

it('counts each person\'s posts and the reports on their posts and comments', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create();
    reportOn($post);
    reportOn($post);
    reportOn(Comment::factory()->for($author)->create());
    reportOn($author);

    $row = app(ModerationUsersQuery::class)->paginate('all', '', null, false, 1)->firstWhere('id', $author->id);

    expect((int) $row->posts_count)->toBe(1)
        ->and((int) $row->reports_on_content)->toBe(3)
        ->and(app(ModerationUsersQuery::class)->reportsOnContentOf($author))->toBe(3);
});

it('keeps accounts made at the same moment in their place across pages', function () {
    $people = User::factory()->count(ModerationUsersQuery::PER_PAGE + 5)->create(['created_at' => now()->subDay()->startOfSecond()]);
    $expected = $people->pluck('id')->sortDesc()->values()->all();

    $first = moderationUsersPage();
    $second = moderationUsersPage(page: 2);

    expect(array_intersect($first, $second))->toBe([])
        ->and([...$first, ...$second])->toBe($expected);
});
