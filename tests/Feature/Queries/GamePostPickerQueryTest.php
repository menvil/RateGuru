<?php

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVariantName;
use App\Models\Post;
use App\Models\User;
use App\Queries\Games\GamePostPickerQuery;
use Illuminate\Support\Facades\Storage;

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

it('searches the title, the id and the author, literally and in any case, only among the posts it may offer', function (string $search) {
    $author = User::factory()->create(['username' => 'sea_lover']);
    Post::factory()->published()->withImage()->create(['title' => 'Other']);
    $post = Post::factory()->published()->withImage()->for($author)->create(['title' => 'Beach at 50% sunset']);
    // Matches the title and the author too, but is not on the site.
    Post::factory()->hidden()->withImage()->for($author)->create(['title' => 'Beach at 50% sunset']);
    $search = $search === 'id' ? '#'.$post->id : $search;

    expect(pickerPage($search))->toBe([$post->id])
        ->and(pickerPage($search, [$post->id]))->toBe([]);
})->with(['beach AT', '50%', 'id', '@sea_lover', 'sea_']);

it('takes % and _ as themselves, never as wildcards', function () {
    $decoy = User::factory()->create(['username' => 'seaXlover']);
    Post::factory()->published()->withImage()->for($decoy)->create(['title' => 'Beach at 500 sunsets']);
    $author = User::factory()->create(['username' => 'sea_lover']);
    $post = Post::factory()->published()->withImage()->for($author)->create(['title' => 'Beach at 50% sunset']);

    expect(pickerPage('at 50%'))->toBe([$post->id])
        ->and(pickerPage('sea_lover'))->toBe([$post->id])
        ->and(pickerPage('beach%sunset'))->toBe([])
        ->and(pickerPage('s_a_lover'))->toBe([]);
});

it('pages through posts of equal votes without moving or repeating any', function () {
    $posts = Post::factory()->published()->withImage()->count(GamePostPickerQuery::PER_PAGE + 3)->create(['upvotes_count' => 10]);

    $first = pickerPage();
    $second = pickerPage(page: 2);

    expect(count($first))->toBe(GamePostPickerQuery::PER_PAGE)
        ->and(count($second))->toBe(3)
        ->and(array_intersect($first, $second))->toBe([])
        ->and([...$first, ...$second])->toBe($posts->sortByDesc('id')->pluck('id')->values()->all());
});

it('offers only photos for a round, and videos too for a post a flow step links to', function () {
    Storage::fake('public');
    $photo = Post::factory()->published()->withImage()->create();
    $video = videoPostWithPosterVariants([], MediaVariantName::PostFeed640);
    $animation = videoPostWithPosterVariants(['media_type' => MediaType::Animation], MediaVariantName::PostFeed640);

    expect(app(GamePostPickerQuery::class)->paginate('', [], 1, photosOnly: true)->pluck('id')->all())->toBe([$photo->id])
        ->and(pickerPage())->toEqualCanonicalizing([$photo->id, $video->id, $animation->id]);
});

it('takes a ready still photo for a game round, never a video\'s poster or a picture still processing', function () {
    $query = app(GamePostPickerQuery::class);
    $photo = Post::factory()->published()->withImage()->create();
    $video = Post::factory()->published()->withImage()->create();
    $video->imageAsset->forceFill(['media_type' => MediaType::Video])->save();
    $processing = Post::factory()->published()->withImage()->create();
    $processing->imageAsset->forceFill(['status' => MediaStatus::Processing])->save();

    expect($query->offersAsRoundPhoto($photo->fresh()))->toBeTrue()
        ->and($query->offersAsRoundPhoto($video->fresh()))->toBeFalse()
        ->and($query->offersAsRoundPhoto($processing->fresh()))->toBeFalse()
        ->and($query->offersAsRoundPhoto(Post::factory()->published()->create(['image_asset_id' => null])))->toBeFalse();
});
