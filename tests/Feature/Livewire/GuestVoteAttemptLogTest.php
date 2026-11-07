<?php

use App\Enums\VoteType;
use App\Exceptions\Observability\SecurityLogUnavailableException;
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
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
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
    $group = RatingGroup::factory()->create(['key' => 'type']);
    $option = RatingOption::factory()->for($group, 'group')->create();

    Livewire::actingAs($user)->test(PostVoting::class, ['postId' => $post->id])->call('vote', VoteType::Up->value);
    Livewire::actingAs($user)->test(CommentVoting::class, ['commentId' => $comment->id])->call('vote', VoteType::Up->value);
    Livewire::actingAs($user)->test(RatingVoting::class, ['post' => $post, 'groupKey' => 'type'])->call('vote', $option->id);

    expect(PostVote::query()->count())->toBe(1)
        ->and(CommentVote::query()->count())->toBe(1)
        ->and(RatingVote::query()->count())->toBe(1)
        ->and(guestVoteAttempts($log))->toBe([]);
});

it('still refuses a guest\'s vote when the security log cannot be written, and reports it without the visitor', function () {
    Exceptions::fake();
    // A file under /dev/null: neither its directory nor the file can ever be created.
    config(['logging.channels.security' => ['driver' => 'single', 'path' => '/dev/null/security.log']]);
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', VoteType::Up->value)
        ->assertSet('error', __('ui.voting.sign_in_to_vote'));

    // Monolog appends the record it was writing to its own message; none of
    // that, and so no address, is in what is reported.
    Exceptions::assertReported(fn (SecurityLogUnavailableException $reported): bool => $reported->getPrevious() === null
        && str_contains($reported->getMessage(), '/dev/null/security')
        && ! str_contains($reported->getMessage(), '127.0.0.1')
        && ! str_contains($reported->getMessage(), GuestVoteAttemptLog::EVENT));
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

it('writes a minute\'s worth of attempts from one address, says when it stops, then waits out the minute', function () {
    $log = fakeSecurityLog();
    $from = fn (string $ip) => $this->app->instance('request', Request::create('/livewire/update', 'POST', server: ['REMOTE_ADDR' => $ip]));
    $from('203.0.113.7');

    foreach (range(1, GuestVoteAttemptLog::LINES_PER_MINUTE + 5) as $attempt) {
        app(GuestVoteAttemptLog::class)->record('post', ['post_id' => 1, 'vote' => 'up']);
    }

    $throttled = collect($log->getRecords())->filter(fn (LogRecord $record): bool => $record->message === GuestVoteAttemptLog::THROTTLED_EVENT);

    expect(guestVoteAttempts($log))->toHaveCount(GuestVoteAttemptLog::LINES_PER_MINUTE)
        ->and($throttled)->toHaveCount(1)
        ->and($throttled->first()->context)->toMatchArray(['ip' => '203.0.113.7', 'kind' => 'post']);

    // Another address is counted on its own.
    $from('198.51.100.4');
    app(GuestVoteAttemptLog::class)->record('post', ['post_id' => 1, 'vote' => 'up']);

    expect(guestVoteAttempts($log))->toHaveCount(GuestVoteAttemptLog::LINES_PER_MINUTE + 1);

    // And the first one is written again once its minute is over.
    $this->travel(61)->seconds();
    $from('203.0.113.7');
    app(GuestVoteAttemptLog::class)->record('post', ['post_id' => 1, 'vote' => 'up']);

    expect(guestVoteAttempts($log))->toHaveCount(GuestVoteAttemptLog::LINES_PER_MINUTE + 2);
});

it('limits the log under a key that holds no client address', function () {
    fakeSecurityLog();
    $post = Post::factory()->published()->create();
    $keys = [];
    Event::listen([KeyWritten::class, CacheHit::class, CacheMissed::class], function (object $event) use (&$keys): void {
        $keys[] = $event->key;
    });

    Livewire::test(PostVoting::class, ['postId' => $post->id])->call('vote', VoteType::Up->value);

    // Keyed with the app key, which nobody trying addresses has.
    expect(collect($keys)->filter(fn (string $key): bool => str_contains($key, 'guest-vote-attempt-log'))->all())->not->toBe([])
        ->and(implode(' ', $keys))->toContain('guest-vote-attempt-log:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key')))
        ->and(implode(' ', $keys))->not->toContain('127.0.0.1')
        // Nor the plain hash of it, which trying every IPv4 address would undo.
        ->and(implode(' ', $keys))->not->toContain(hash('sha256', '127.0.0.1'));
});
