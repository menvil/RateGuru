<?php

use App\Livewire\Feed\PostFeed;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * The feed with the viewer's language first, while the feature is on: fixed for as long as the feed is looked
 * through, never in the Following feed or a search, and counting a member's fresh posts since their previous visit.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00'));
    app()->setLocale('ru');
});

function languageRankingOn(array $values = []): void
{
    ProjectSettings::factory()->create(['language_ranking' => ['enabled' => true, ...$values]]);
}

function publishedIn(?string $language, int $hoursAgo, string $title): Post
{
    return Post::factory()->published()->create(['language' => $language, 'title' => $title, 'published_at' => now()->subHours($hoursAgo), 'created_at' => now()->subHours($hoursAgo)]);
}

it('keeps today\'s order while the feature is off', function () {
    publishedIn('ru', 5, 'Russian Dish');
    publishedIn('en', 1, 'English Dish');

    Livewire::test(PostFeed::class)
        ->assertSet('languageOrder', null)
        ->assertSeeInOrder(['English Dish', 'Russian Dish']);
});

it('puts fresh posts in a guest\'s language first', function () {
    languageRankingOn();
    publishedIn('ru', 5, 'Russian Dish');
    publishedIn('en', 1, 'English Dish');
    publishedIn('de', 2, 'German Dish');

    Livewire::test(PostFeed::class)
        ->assertSeeInOrder(['Russian Dish', 'English Dish', 'German Dish']);
});

it('counts a member\'s fresh posts since their previous visit', function () {
    languageRankingOn();
    $member = User::factory()->create();
    $member->forceFill(['previous_visit_at' => now()->subDays(2), 'last_seen_at' => now()])->save();
    publishedIn('ru', 24 * 3, 'Seen Russian Dish');
    publishedIn('ru', 24, 'New Russian Dish');
    publishedIn('en', 30, 'English Dish');

    $this->actingAs($member);

    Livewire::test(PostFeed::class)
        ->assertSeeInOrder(['New Russian Dish', 'English Dish', 'Seen Russian Dish']);
});

it('leaves the Following feed and a search in their own order', function (array $parameters) {
    languageRankingOn();
    $this->actingAs(User::factory()->create());

    Livewire::test(PostFeed::class, $parameters)->assertSet('languageOrder', null);
})->with([
    'following' => [['followingOnly' => true]],
    'a search' => [['search' => 'dish']],
]);

it('ranks Top by language over all time', function () {
    languageRankingOn();

    Livewire::test(PostFeed::class, ['sort' => 'top'])->assertSet('languageOrder.since', null);
});

it('lifts posts in Hot only above the floor when one is set', function () {
    languageRankingOn(['hot_floor_percentile' => 50]);
    foreach ([0.1, 0.2, 0.3] as $score) {
        Post::factory()->published()->create(['hot_score' => $score, 'published_at' => now()->subHour()]);
    }

    Livewire::test(PostFeed::class, ['sort' => 'hot'])->assertSet('languageOrder.hot_floor', 0.2);
    Livewire::test(PostFeed::class, ['sort' => 'newest'])->assertSet('languageOrder.hot_floor', null);
});

it('keeps the order it was opened with for as long as it is looked through', function () {
    languageRankingOn();

    expect(fn () => Livewire::test(PostFeed::class)->set('languageOrder', null))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('shows strong posts in other languages on the home feed in New and Hot only', function (array $parameters, bool $hits) {
    languageRankingOn();
    Post::factory()->published()->create(['language' => 'de', 'upvotes_count' => 50, 'published_at' => now()->subHours(3)]);
    Tag::factory()->create(['slug' => 'food']);

    $component = Livewire::test(PostFeed::class, $parameters);

    expect($component->get('hitIds') !== [])->toBe($hits);
})->with([
    'New' => [['sort' => 'newest'], true],
    'Hot' => [['sort' => 'hot'], true],
    'Top' => [['sort' => 'top'], false],
    'a tag' => [['sort' => 'newest', 'tag' => 'food'], false],
    'a category' => [['sort' => 'newest', 'category' => ['food']], false],
]);

it('shows none when the setting asks for none', function () {
    languageRankingOn(['hit_every' => 0]);
    Post::factory()->published()->create(['language' => 'de', 'upvotes_count' => 50, 'published_at' => now()->subHours(3)]);

    Livewire::test(PostFeed::class)->assertSet('hitIds', []);
});

it('puts the strong post at its place in the feed', function () {
    languageRankingOn(['hit_every' => 3]);
    foreach (range(1, 5) as $i) {
        publishedIn('ru', $i, "Russian Dish {$i}");
    }
    Post::factory()->published()->create(['language' => 'de', 'title' => 'German Favourite', 'upvotes_count' => 50, 'published_at' => now()->subHours(30)]);

    Livewire::test(PostFeed::class)
        ->assertSeeInOrder(['Russian Dish 1', 'Russian Dish 2', 'German Favourite', 'Russian Dish 3']);
});
