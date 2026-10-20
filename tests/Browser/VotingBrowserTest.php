<?php

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Database\Seeders\DefaultRatingConfigurationSeeder;

use function Pest\Laravel\actingAs;

it('allows authenticated user to upvote a post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Browser Upvote Test Post',
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    actingAs($user);

    visit(route('feed'))
        ->assertSee('Browser Upvote Test Post')
        ->click("[data-testid=\"post-upvote-button-{$post->id}\"]")
        ->assertSeeIn("[data-testid=\"post-upvote-count-{$post->id}\"]", '1')
        ->assertAttribute("[data-testid=\"post-upvote-button-{$post->id}\"]", 'aria-pressed', 'true');

    $this->assertDatabaseHas('post_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
        'type' => VoteType::Up->value,
    ]);
});

it('allows authenticated user to vote on a post type option', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Browser Type Vote Test Post',
    ]);
    $type = RatingGroup::query()->where('key', 'type')->firstOrFail();
    $option = $type->options()->active()->firstOrFail();

    actingAs($user);

    visit(route('feed'))
        ->assertSee('Browser Type Vote Test Post')
        ->click("[data-testid=\"rating-option-{$post->id}-{$option->id}\"]")
        ->assertPresent("[data-testid=\"rating-option-{$post->id}-results\"]");

    $this->assertDatabaseHas('rating_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
        'rating_group_id' => $type->id,
        'rating_option_id' => $option->id,
    ]);
});

it('shares a question\'s whole width equally among its answers, in one row however many there are', function (int $answers, array $screen) {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create(['title' => 'Browser Answer Width Post']);
    $group = RatingGroup::factory()->create(['key' => 'answer_width', 'max_options' => 11, 'sort_order' => 0]);

    foreach (range(0, $answers - 1) as $index) {
        RatingOption::factory()->create(['rating_group_id' => $group->id, 'label' => (string) $index, 'sort_order' => $index]);
    }

    actingAs($user);

    $page = visit(route('feed'))->resize(...$screen)->assertSee('Browser Answer Width Post');
    $row = $page->script("(() => {
        const buttons = [...document.querySelectorAll('button[data-testid^=\"rating-option-{$post->id}-\"]')];
        const row = buttons[0].parentElement.getBoundingClientRect();
        const boxes = buttons.map((button) => button.getBoundingClientRect());

        return {
            widths: boxes.map((box) => box.width),
            rows: new Set(boxes.map((box) => Math.round(box.top))).size,
            margins: [Math.round(boxes[0].left - row.left), Math.round(row.right - boxes[boxes.length - 1].right)],
            cut: buttons.filter((button) => button.firstElementChild.scrollWidth > button.firstElementChild.clientWidth).map((button) => button.textContent.trim()),
        };
    })()");

    expect($row['widths'])->toHaveCount($answers)
        ->and(max($row['widths']) - min($row['widths']))->toBeLessThan(1)
        ->and($row['rows'])->toBe(1)
        ->and($row['margins'])->toBe([0, 0])
        // Short labels such as a score's numbers stay whole even on a phone.
        ->and($row['cut'])->toBe([]);
})->with([2, 11])->with(['desktop' => [[1440, 900]], 'phone' => [[375, 812]]]);
