<?php

use App\Enums\ContentLanguageCheckStatus;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Models\User;

/*
 * languages:report: the published posts by language, where the checks stand, and how often the readings agreed.
 */

it('reports the posts by language and how well their languages were told', function () {
    $author = User::factory()->create();
    $author->forceFill(['locale' => 'ru'])->save();
    $russian = Post::factory()->published()->create(['language' => 'ru', 'user_id' => $author->id]);
    $english = Post::factory()->published()->create(['language' => 'en', 'user_id' => $author->id]);
    Post::factory()->published()->create(['language' => null]);
    ContentLanguageCheck::query()->create(['post_id' => $russian->id, 'local_language' => 'ru', 'ai_language' => 'ru', 'status' => ContentLanguageCheckStatus::Done, 'run_after' => now()]);
    ContentLanguageCheck::query()->create(['post_id' => $english->id, 'local_language' => 'de', 'ai_language' => 'en', 'status' => ContentLanguageCheckStatus::Done, 'run_after' => now()]);

    $this->artisan('languages:report')
        ->expectsTable(['Language', 'Posts', 'Share'], [['en', 1, '33%'], ['none', 1, '33%'], ['ru', 1, '33%']])
        ->expectsOutputToContain("The server's own reading agreed with OpenAI on 1 of 2 texts both read (50%).")
        ->expectsOutputToContain("A post's text was in its author's language in 1 of 2 posts (50%).")
        ->assertSuccessful();
});
