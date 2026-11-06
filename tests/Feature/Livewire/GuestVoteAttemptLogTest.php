<?php

use App\Enums\VoteType;
use App\Livewire\Comments\CommentVoting;
use App\Livewire\Posts\PostVoting;
use App\Livewire\Voting\RatingVoting;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use App\Support\Observability\GuestVoteAttemptLog;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/*
 * A guest who tries to vote is still refused, and the attempt is now written
 * to the security log with the visitor's IP address, browser and page, for
 * tracing spam and abuse by visitors who never sign in.
 */

/** The security channel, swapped for a handler the test can read. */
function fakeSecurityLog(): TestHandler
{
    config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);

    return Log::channel('security')->getLogger()->getHandlers()[0];
}

/** @return list<array<string, mixed>> */
function guestVoteAttempts(TestHandler $log): array
{
    return collect($log->getRecords())
        ->filter(fn (LogRecord $record): bool => $record->message === GuestVoteAttemptLog::EVENT)
        ->map(fn (LogRecord $record): array => $record->context)
        ->values()
        ->all();
}

it('keeps the security log in a file a day, for 30 days', function () {
    expect(config('logging.channels.security'))
        ->toMatchArray([
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'days' => 30,
        ]);
});

it('records a guest\'s post vote with the visitor, and still refuses it', function () {
    $log = fakeSecurityLog();
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', VoteType::Up->value)
        ->assertSet('error', __('ui.voting.sign_in_to_vote'));

    expect(PostVote::query()->count())->toBe(0)
        ->and(guestVoteAttempts($log))->toHaveCount(1)
        ->and(guestVoteAttempts($log)[0])->toMatchArray([
            'kind' => 'post',
            'post_id' => $post->id,
            'vote' => 'up',
            'ip' => '127.0.0.1',
        ])
        ->toHaveKeys(['user_agent', 'referer', 'request_id'])
        ->not->toHaveKey('user_id');
});

it('records a guest\'s comment vote, and still refuses it', function () {
    $log = fakeSecurityLog();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create();

    Livewire::test(CommentVoting::class, ['commentId' => $comment->id])
        ->call('vote', VoteType::Down->value);

    expect(CommentVote::query()->count())->toBe(0)
        ->and(guestVoteAttempts($log))->toHaveCount(1)
        ->and(guestVoteAttempts($log)[0])->toMatchArray([
            'kind' => 'comment',
            'comment_id' => $comment->id,
            'vote' => 'down',
            'ip' => '127.0.0.1',
        ]);
});

it('records a guest\'s rating vote, and still refuses it', function () {
    $log = fakeSecurityLog();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'type']);
    $option = RatingOption::factory()->for($group, 'group')->create();

    Livewire::test(RatingVoting::class, ['post' => $post, 'groupKey' => 'type'])
        ->call('vote', $option->id)
        ->assertSet('error', __('ui.voting.sign_in_to_vote'));

    expect(RatingVote::query()->count())->toBe(0)
        ->and(guestVoteAttempts($log))->toHaveCount(1)
        ->and(guestVoteAttempts($log)[0])->toMatchArray([
            'kind' => 'rating',
            'post_id' => $post->id,
            'rating_group' => 'type',
            'rating_option_id' => $option->id,
            'ip' => '127.0.0.1',
        ]);
});

it('records nothing when a signed-in account votes', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create();

    Livewire::actingAs($user)->test(PostVoting::class, ['postId' => $post->id])->call('vote', VoteType::Up->value);
    Livewire::actingAs($user)->test(CommentVoting::class, ['commentId' => $comment->id])->call('vote', VoteType::Up->value);

    expect(PostVote::query()->count())->toBe(1)
        ->and(CommentVote::query()->count())->toBe(1)
        ->and(guestVoteAttempts($log))->toBe([]);
});

it('takes the visitor\'s address, browser and page from the request, cut to a sane length', function () {
    $log = fakeSecurityLog();
    $this->app->instance('request', Request::create('/livewire/update', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => str_repeat('a', 5000),
        'HTTP_REFERER' => 'https://example.test/posts/1',
    ]));

    app(GuestVoteAttemptLog::class)->record('post', ['post_id' => 1, 'vote' => 'up']);

    expect(guestVoteAttempts($log)[0])->toMatchArray([
        'ip' => '203.0.113.7',
        'user_agent' => str_repeat('a', 512),
        'referer' => 'https://example.test/posts/1',
    ]);
});

it('keeps the visitor out of Laravel\'s log event, which Sentry turns into breadcrumbs', function () {
    // Faked first, so the channel is built around the fake: writing through
    // Laravel's channel wrapper would be caught here.
    Event::fake([MessageLogged::class]);
    fakeSecurityLog();
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])->call('vote', VoteType::Up->value);

    Event::assertNotDispatched(MessageLogged::class);
});
