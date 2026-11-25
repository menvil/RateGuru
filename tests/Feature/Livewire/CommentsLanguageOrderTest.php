<?php

use App\Livewire\Comments\CommentsSection;
use App\Models\Comment;
use App\Models\Post;
use App\Models\ProjectSettings;
use Livewire\Livewire;

/*
 * A thread with comments in the viewer's language first, while the feature and its comments setting are on.
 */

beforeEach(function () {
    app()->setLocale('ru');
});

function threadWithTwoLanguages(): Post
{
    $post = Post::factory()->published()->create();
    Comment::factory()->for($post)->create(['language' => 'en', 'body' => 'An English remark', 'created_at' => now()->subMinute()]);
    Comment::factory()->for($post)->create(['language' => 'ru', 'body' => 'A Russian remark', 'created_at' => now()->subMinutes(5)]);

    return $post;
}

it('puts comments in the viewer\'s language first while the feature is on', function () {
    ProjectSettings::factory()->create(['language_ranking' => ['enabled' => true]]);

    Livewire::test(CommentsSection::class, ['postId' => threadWithTwoLanguages()->id])
        ->call('setCommentSort', 'newest')
        ->assertSeeInOrder(['A Russian remark', 'An English remark']);
});

it('keeps the thread\'s order while the feature or its comments setting is off', function (array $ranking) {
    ProjectSettings::factory()->create(['language_ranking' => $ranking]);

    Livewire::test(CommentsSection::class, ['postId' => threadWithTwoLanguages()->id])
        ->call('setCommentSort', 'newest')
        ->assertSeeInOrder(['An English remark', 'A Russian remark']);
})->with([
    'feature off' => [['enabled' => false]],
    'comments setting off' => [['enabled' => true, 'comments_first' => false]],
]);
