<?php

use App\Enums\ContentLanguageCheckStatus;
use App\Models\Comment;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\LanguageDemoSeeder;
use Illuminate\Support\Facades\Storage;

/** A few days of the multilingual site among a handful of members, so it plays in seconds. */
class SmallLanguageDemoSeeder extends LanguageDemoSeeder
{
    protected function memberCount(): int
    {
        return 40;
    }

    protected function days(): int
    {
        return 3;
    }

    protected function postsPerDay(): int
    {
        return 6;
    }
}

/*
 * A month of a multilingual site through the site's own actions: members of many languages posting, commenting and
 * voting mostly among their own, every text given its language as on the site, each post's language worked out —
 * and only once.
 */

beforeEach(function () {
    Storage::fake('public');
});

it('plays a multilingual site through the site\'s own actions, each text given its language', function () {
    (new SmallLanguageDemoSeeder)->__invoke();

    $members = User::query()->where('email', 'like', 'langdemo%@demo.test')->get();
    $posts = Post::query()->published()->get();

    expect($members)->toHaveCount(40)
        ->and($members->pluck('locale')->filter()->unique()->count())->toBeGreaterThan(2)
        ->and($members->whereNull('locale'))->not->toBeEmpty()
        ->and($posts->count())->toBeGreaterThan(15)
        ->and($posts->pluck('language')->filter()->unique()->count())->toBeGreaterThan(2)
        ->and($posts->whereNotNull('language_stale_at'))->toBeEmpty()
        ->and(Comment::query()->whereNotNull('language')->exists())->toBeTrue()
        ->and(ContentLanguageCheck::query()->where('status', ContentLanguageCheckStatus::Pending)->exists())->toBeTrue()
        ->and(ContentLanguageCheck::query()->count())->toBe(Post::query()->count() + Comment::query()->count());
});

it('adds nothing when run again', function () {
    (new SmallLanguageDemoSeeder)->__invoke();
    $posts = Post::query()->count();

    (new SmallLanguageDemoSeeder)->__invoke();

    expect(Post::query()->count())->toBe($posts);
});
