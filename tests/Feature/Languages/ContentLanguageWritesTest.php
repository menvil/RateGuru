<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Locale\ChangeLocaleAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Users\UpdateUserLocaleAction;
use App\Actions\Votes\VotePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\ContentLanguageCheckPriority;
use App\Enums\ContentLanguageCheckStatus;
use App\Enums\VoteType;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use Illuminate\Http\Request;

/*
 * A post and a comment get their language when they are written — told on this server at once, checked again by
 * OpenAI later — and whatever counts towards a post's language marks it to be worked out again in the background,
 * within the one update the write already makes of the post.
 */

beforeEach(function () {
    ProjectSettings::factory()->create(['enabled_locales' => supportedLocales()]);
});

function memberSpeaking(?string $locale): User
{
    $user = User::factory()->create();
    $user->forceFill(['locale' => $locale])->save();

    return $user;
}

function postBy(User $author, string $title, ?string $description = null): Post
{
    return app(CreatePostAction::class)->handle($author, new CreatePostData(title: $title, description: $description))->fresh();
}

it('gives a new post the language of its text and its author, and queues its check', function () {
    $post = postBy(memberSpeaking('ru'), 'Очень красивая фотография', 'Мне очень нравится этот вид');
    $check = ContentLanguageCheck::query()->where('post_id', $post->id)->sole();

    expect($post->language)->toBe('ru')
        ->and($post->language_share)->toBe(1.0)
        ->and($check->local_language)->toBe('ru')
        ->and($check->local_weight)->toBe(1.0)
        ->and($check->status)->toBe(ContentLanguageCheckStatus::Pending)
        ->and($check->priority)->toBe(ContentLanguageCheckPriority::Live);
});

it('takes a post\'s text over an author of another language', function () {
    $post = postBy(memberSpeaking('en'), 'Wunderschönes Foto aus den Bergen', 'Gefällt mir sehr, danke');

    expect($post->language)->toBe('de')
        ->and($post->language_share)->toBe(0.6);
});

it('gives a post of only emoji its author\'s language, and asks OpenAI nothing about it', function () {
    $post = postBy(memberSpeaking('bg'), '😍🔥 10/10');
    $check = ContentLanguageCheck::query()->where('post_id', $post->id)->sole();

    expect($post->language)->toBe('bg')
        ->and($check->local_language)->toBeNull()
        ->and($check->status)->toBe(ContentLanguageCheckStatus::Skipped)
        ->and($check->finished_at)->not->toBeNull();
});

it('gives no language to a post that tells none by an author with none', function () {
    $post = postBy(memberSpeaking(null), 'wow 😍');

    expect($post->language)->toBeNull()
        ->and($post->language_share)->toBeNull();
});

it('gives a comment its language, queues its check, and marks its post to be worked out again', function () {
    $post = postBy(memberSpeaking('en'), 'A lovely view from the mountains');
    expect($post->language_stale_at)->toBeNull();

    $comment = app(AddCommentAction::class)->handle(memberSpeaking('ru'), $post, 'Какой прекрасный вид, спасибо!')->fresh();

    expect($comment->language)->toBe('ru')
        ->and(ContentLanguageCheck::query()->where('comment_id', $comment->id)->sole()->status)->toBe(ContentLanguageCheckStatus::Pending)
        ->and($post->fresh()->language_stale_at)->not->toBeNull();
});

it('gives an emoji-only comment no language', function () {
    $post = postBy(memberSpeaking('en'), 'A lovely view from the mountains');

    $comment = app(AddCommentAction::class)->handle(memberSpeaking('ru'), $post, '😍😍😍')->fresh();

    expect($comment->language)->toBeNull()
        ->and(ContentLanguageCheck::query()->where('comment_id', $comment->id)->sole()->status)->toBe(ContentLanguageCheckStatus::Skipped);
});

it('marks a post to be worked out again when someone with a language votes, not when someone without one does', function () {
    $post = postBy(memberSpeaking('en'), 'A lovely view from the mountains');

    app(VotePostAction::class)->handle(memberSpeaking(null), $post, VoteType::Up);

    expect($post->fresh()->language_stale_at)->toBeNull();

    app(VotePostAction::class)->handle(memberSpeaking('de'), $post, VoteType::Up);

    expect($post->fresh()->language_stale_at)->not->toBeNull();
});

it('marks an author\'s posts to be worked out again when their language changes, not when it stays', function (Closure $change) {
    $author = memberSpeaking('en');
    $post = postBy($author, 'A lovely view from the mountains');

    $change($author, 'en');

    expect($post->fresh()->language_stale_at)->toBeNull();

    $change($author->fresh(), 'ru');

    expect($post->fresh()->language_stale_at)->not->toBeNull();
})->with([
    'from the profile' => [fn (User $user, string $locale) => app(UpdateUserLocaleAction::class)->handle($user, $locale)],
    'from the header' => [function (User $user, string $locale) {
        $request = Request::create('/locale', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(ChangeLocaleAction::class)->execute($locale, $request);
    }],
]);

it('marks an anonymised author\'s posts to be worked out again without their language', function () {
    $author = memberSpeaking('ru');
    $post = postBy($author, 'Wow 😍');

    app(AnonymizeUserAccountAction::class)->execute($author);

    expect($post->fresh()->language_stale_at)->not->toBeNull();
});
