<?php

use App\Enums\GameFlowStepType;
use App\Models\Post;
use App\Queries\Games\GameFlowFactsQuery;
use App\Support\Games\Flow\FlowStepDraft;
use Illuminate\Support\Facades\DB;

it('reads whether the posts the steps link to are published at once, and never a deleted one', function () {
    $published = Post::factory()->published()->create(['title' => 'Shown']);
    $hidden = Post::factory()->hidden()->create(['title' => 'Hidden']);
    // Deleted, and still marked published.
    $deleted = Post::factory()->published()->create(['title' => 'Gone']);
    $deleted->delete();
    $steps = fn (Post ...$posts): array => array_map(fn (Post $post): FlowStepDraft => new FlowStepDraft('s'.$post->id, GameFlowStepType::GoToPost, settings: ['post_id' => $post->id]), $posts);
    $queries = function (array $steps): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(GameFlowFactsQuery::class)->for($steps);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $posts = app(GameFlowFactsQuery::class)->for($steps($published, $hidden, $deleted))->posts;
    ksort($posts);

    expect($posts)->toBe([
        $published->id => ['title' => 'Shown', 'published' => true],
        $hidden->id => ['title' => 'Hidden', 'published' => false],
        $deleted->id => ['title' => 'Gone', 'published' => false],
    ])
        ->and($queries($steps($published, $hidden)))->toBe($queries($steps($published, $hidden, Post::factory()->published()->create())));
});
