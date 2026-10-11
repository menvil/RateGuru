<?php

use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\VoteType;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingGroup;
use App\Models\RatingVote;
use App\Models\Report;
use App\Queries\Feed\FeedQuery;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * The whole seed, the way `php artisan db:seed` runs it. Each demo seeder
 * builds on the rows the ones before it wrote, so most of what is worth
 * checking about the demo data exists only once the whole chain has run, and
 * running the chain once per claim made this the slowest directory in every
 * database job. What can be checked on one seeder by itself stays in that
 * seeder's own file.
 */

beforeEach(function () {
    Storage::fake('public');
});

/**
 * Every stored counter on a seeded post or comment that disagrees with the
 * rows it counts, keyed by what it belongs to. Empty when they all agree.
 *
 * @return array<string, array{stored: int, counted: int}>
 */
function seededCounterMismatches(): array
{
    $reportCounts = fn (string $type) => Report::query()
        ->where('target_type', $type)
        ->selectRaw('target_id, count(*) as aggregate')
        ->groupBy('target_id')
        ->pluck('aggregate', 'target_id');
    $postReports = $reportCounts(Post::class);
    $commentReports = $reportCounts(Comment::class);

    $mismatches = [];
    $compare = function (string $owner, int $stored, int $counted) use (&$mismatches): void {
        if ($stored !== $counted) {
            $mismatches[$owner] = ['stored' => $stored, 'counted' => $counted];
        }
    };

    $posts = Post::query()
        ->withCount([
            'postVotes as counted_upvotes' => fn ($query) => $query->where('type', VoteType::Up),
            'postVotes as counted_downvotes' => fn ($query) => $query->where('type', VoteType::Down),
            'comments as counted_comments' => fn ($query) => $query->where('status', CommentStatus::Visible),
        ])
        ->get();

    foreach ($posts as $post) {
        $compare("post {$post->id} upvotes", $post->upvotes_count, $post->counted_upvotes);
        $compare("post {$post->id} downvotes", $post->downvotes_count, $post->counted_downvotes);
        $compare("post {$post->id} comments", $post->comments_count, $post->counted_comments);
        $compare("post {$post->id} reports", $post->reports_count, (int) ($postReports[$post->id] ?? 0));
    }

    foreach (Comment::query()->get() as $comment) {
        $compare("comment {$comment->id} reports", $comment->reports_count, (int) ($commentReports[$comment->id] ?? 0));
    }

    return $mismatches;
}

it('seeds the default configuration and demo posts of every status, of which the feed shows only the published', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Category::query()->active()->count())->toBe(3)
        ->and(RatingGroup::query()->whereIn('key', ['type', 'attribute'])->count())->toBe(2);

    expect(Post::query()->doesntHave('user')->exists())->toBeFalse()
        ->and(Post::query()->doesntHave('tags')->exists())->toBeFalse();

    $published = Post::query()->where('status', PostStatus::Published);

    expect((clone $published)->whereNotNull('category_id')->exists())->toBeTrue()
        ->and((clone $published)->whereNull('category_id')->exists())->toBeTrue();

    $imagePaths = Post::query()->whereNotNull('image_asset_id')->with('imageAsset')->get()->pluck('imageAsset.path');

    expect($imagePaths)->toHaveCount(19);

    foreach ($imagePaths as $imagePath) {
        Storage::disk('public')->assertExists($imagePath);
    }

    $titles = strtolower(Post::query()->pluck('title')->implode(' '));

    expect($titles)->not->toContain('pasta')
        ->not->toContain('sushi')
        ->not->toContain('tacos');

    $feed = app(FeedQuery::class)->get();

    expect($feed)->not->toBeEmpty()
        ->and($feed->every(fn (Post $post): bool => $post->status === PostStatus::Published))->toBeTrue();

    $unpublishedTitles = Post::query()
        ->whereIn('status', [PostStatus::Pending, PostStatus::Hidden])
        ->pluck('title');

    expect($unpublishedTitles)->toHaveCount(5);

    $page = $this->get(route('feed'))->assertOk();

    foreach ($unpublishedTitles as $title) {
        $page->assertDontSee($title);
    }
});

it('seeds comments, votes and reports, and post and comment counters that match them', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Comment::query()->count())->toBeGreaterThanOrEqual(10)
        ->and(Comment::query()->doesntHave('post')->exists())->toBeFalse()
        ->and(Comment::query()->doesntHave('user')->exists())->toBeFalse()
        ->and(Comment::query()->pluck('body')->contains(fn (string $body): bool => str_contains(strtolower($body), 'plating')))->toBeFalse();

    expect(PostVote::query()->exists())->toBeTrue()
        ->and(RatingVote::query()->exists())->toBeTrue()
        ->and(Post::query()->where('status', PostStatus::Published)->where('hot_score', '>', 0)->exists())->toBeTrue();

    expect(Report::query()->count())->toBeGreaterThanOrEqual(4)
        ->and(Report::query()->where('target_type', Post::class)->exists())->toBeTrue()
        ->and(Report::query()->where('target_type', Comment::class)->exists())->toBeTrue();

    expect(seededCounterMismatches())->toBe([]);
});

it('adds nothing and keeps its counters right when it runs again on a seeded database', function () {
    $rowCounts = fn (): array => collect(Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false))
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->sortKeys()
        ->all();

    $this->seed(DatabaseSeeder::class);
    $afterFirstRun = $rowCounts();

    $this->seed(DatabaseSeeder::class);

    expect($rowCounts())->toBe($afterFirstRun)
        ->and(seededCounterMismatches())->toBe([]);
});
