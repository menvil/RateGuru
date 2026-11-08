<?php

use App\Enums\MediaStatus;
use App\Models\Post;
use App\Models\User;
use App\Queries\Games\GamePostPickerQuery;

/**
 * The posts a game round may show: published, not deleted, with a ready
 * picture, the most voted first, searched literally over the title, the id
 * and the author's username, in an order that keeps every post in its place
 * across pages.
 */
function pickerPage(string $search = '', array $except = [], int $page = 1): array
{
    return app(GamePostPickerQuery::class)->paginate($search, $except, $page)->pluck('id')->all();
}

it('offers only published posts with a ready picture, the most voted first', function () {
    $few = Post::factory()->published()->withImage()->create(['upvotes_count' => 5]);
    $many = Post::factory()->published()->withImage()->create(['upvotes_count' => 500]);
    Post::factory()->hidden()->withImage()->create();
    Post::factory()->pending()->withImage()->create();
    Post::factory()->published()->create(['image_asset_id' => null]);
    $deleted = Post::factory()->published()->withImage()->create();
    $deleted->delete();
    $processing = Post::factory()->published()->withImage()->create();
    $processing->imageAsset->forceFill(['status' => MediaStatus::Processing])->save();

    expect(pickerPage())->toBe([$many->id, $few->id]);
});

it('leaves out the posts it is asked to', function () {
    $a = Post::factory()->published()->withImage()->create();
    $b = Post::factory()->published()->withImage()->create();

    expect(pickerPage(except: [$a->id]))->toBe([$b->id]);
});

it('searches the title, the id and the author, literally and in any case', function (string $search) {
    $author = User::factory()->create(['username' => 'sea_lover']);
    Post::factory()->published()->withImage()->create(['title' => 'Other']);
    $post = Post::factory()->published()->withImage()->for($author)->create(['title' => 'Beach at 50% sunset']);

    expect(pickerPage($search === 'id' ? '#'.$post->id : $search))->toBe([$post->id]);
})->with(['beach AT', '50%', 'id', '@sea_lover', 'sea_']);

it('pages through posts of equal votes without moving or repeating any', function () {
    $posts = Post::factory()->published()->withImage()->count(GamePostPickerQuery::PER_PAGE + 3)->create(['upvotes_count' => 10]);

    $first = pickerPage();
    $second = pickerPage(page: 2);

    expect(array_intersect($first, $second))->toBe([])
        ->and([...$first, ...$second])->toBe($posts->sortByDesc('id')->pluck('id')->values()->all());
});
