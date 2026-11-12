<?php

use App\Actions\Users\PurgeUnconfirmedAccountAction;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\UnconfirmedAccountPurgeOutcome;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\GameAnswer;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\User;
use App\Queries\Users\UnconfirmedAccountsQuery;
use Illuminate\Auth\Notifications\VerifyEmail;

/*
 * An account that signed up with an email address and did not confirm one
 * within a week is removed with everything it did
 * (docs/architecture/user-lifecycle.md, "Unconfirmed accounts"). Nobody who
 * confirmed an address once, signs in with Google or Facebook, is staff or a
 * Persona is ever among them.
 */

function unconfirmedMember(int $daysAgo = 8): User
{
    return User::factory()->unverified()->create(['created_at' => now()->subDays($daysAgo)]);
}

function purgeUnconfirmed(User $user, bool $dryRun = false): UnconfirmedAccountPurgeOutcome
{
    return app(PurgeUnconfirmedAccountAction::class)->handle((int) $user->id, UnconfirmedAccountsQuery::cutoff(), $dryRun);
}

it('removes an unconfirmed account with everything it did, and counts again what others wrote', function () {
    $bot = unconfirmedMember();
    $member = User::factory()->create();
    $other = User::factory()->create();

    // Its own post, which a member commented on and voted on.
    $botPost = Post::factory()->published()->for($bot)->create();
    $commentOnBotPost = Comment::factory()->for($member)->create(['post_id' => $botPost->id]);
    PostVote::factory()->create(['post_id' => $botPost->id, 'user_id' => $member->id, 'type' => VoteType::Up]);

    // A member's post it commented on, answered, voted on and reported.
    $post = Post::factory()->published()->for($member)->create();
    $leaf = Comment::factory()->for($bot)->create(['post_id' => $post->id]);
    $answered = Comment::factory()->for($bot)->create(['post_id' => $post->id, 'upvotes_count' => 1]);
    $reply = Comment::factory()->for($other)->create(['post_id' => $post->id, 'parent_id' => $answered->id]);
    $memberComment = Comment::factory()->for($member)->create(['post_id' => $post->id, 'upvotes_count' => 1, 'reports_count' => 1]);
    $botReply = Comment::factory()->for($bot)->create(['post_id' => $post->id, 'parent_id' => $memberComment->id]);
    PostVote::factory()->create(['post_id' => $post->id, 'user_id' => $bot->id, 'type' => VoteType::Up]);
    PostVote::factory()->create(['post_id' => $post->id, 'user_id' => $other->id, 'type' => VoteType::Down]);
    CommentVote::factory()->create(['comment_id' => $memberComment->id, 'user_id' => $bot->id, 'type' => VoteType::Up]);
    CommentVote::factory()->create(['comment_id' => $answered->id, 'user_id' => $other->id, 'type' => VoteType::Up]);
    RatingVote::factory()->create(['post_id' => $post->id, 'user_id' => $bot->id]);
    Report::factory()->create(['reporter_id' => $bot->id, 'target_type' => Post::class, 'target_id' => $post->id]);
    Report::factory()->create(['reporter_id' => $bot->id, 'target_type' => Comment::class, 'target_id' => $memberComment->id]);
    Report::factory()->create(['reporter_id' => $other->id, 'target_type' => User::class, 'target_id' => $bot->id]);
    $post->forceFill(['upvotes_count' => 1, 'downvotes_count' => 1, 'comments_count' => 5, 'reports_count' => 1])->save();

    // A game it played.
    $attempt = GameAttempt::factory()->create(['audience' => GameAudience::Authenticated, 'user_id' => $bot->id, 'visitor_hash' => null]);
    GameAnswer::query()->forceCreate(['attempt_id' => $attempt->id, 'round_index' => 0, 'round_key' => 'r1', 'option_position' => 0, 'outcome' => 'correct', 'points' => 1, 'answered_at' => now()]);
    (new GameEvent)->forceFill(['game_id' => $attempt->game_id, 'version_id' => $attempt->version_id, 'attempt_id' => $attempt->id, 'audience' => GameAudience::Authenticated, 'user_id' => $bot->id, 'type' => GameEventType::Started, 'occurred_at' => now()])->save();

    expect(purgeUnconfirmed($bot))->toBe(UnconfirmedAccountPurgeOutcome::Purged);

    // What it wrote is gone, and its own post with everything on it.
    expect(Post::withTrashed()->find($botPost->id))->toBeNull()
        ->and(Comment::withTrashed()->find($commentOnBotPost->id))->toBeNull()
        ->and(Comment::withTrashed()->find($leaf->id))->toBeNull()
        ->and(Comment::withTrashed()->find($botReply->id))->toBeNull();

    // A comment others answered stays as the thread's "[comment deleted]".
    $anchor = Comment::withTrashed()->findOrFail($answered->id);
    expect($anchor->trashed())->toBeTrue()
        ->and($anchor->body)->toBe('')
        ->and($anchor->upvotes_count)->toBe(0)
        ->and(CommentVote::query()->where('comment_id', $anchor->id)->exists())->toBeFalse()
        ->and(Comment::query()->find($reply->id))->not->toBeNull();

    // What others wrote is counted again without it.
    $post->refresh();
    $memberComment->refresh();
    expect($post->upvotes_count)->toBe(0)
        ->and($post->downvotes_count)->toBe(1)
        ->and($post->comments_count)->toBe(2)
        ->and($post->reports_count)->toBe(0)
        ->and($memberComment->upvotes_count)->toBe(0)
        ->and($memberComment->reports_count)->toBe(0);

    expect(PostVote::query()->where('user_id', $bot->id)->exists())->toBeFalse()
        ->and(CommentVote::query()->where('user_id', $bot->id)->exists())->toBeFalse()
        ->and(RatingVote::query()->where('user_id', $bot->id)->exists())->toBeFalse()
        ->and(Report::query()->where('reporter_id', $bot->id)->exists())->toBeFalse()
        ->and(Report::query()->where('target_type', User::class)->where('target_id', $bot->id)->exists())->toBeFalse()
        ->and(GameAttempt::query()->whereKey($attempt->id)->exists())->toBeFalse()
        ->and(GameAnswer::query()->where('attempt_id', $attempt->id)->exists())->toBeFalse()
        ->and(GameEvent::query()->where('attempt_id', $attempt->id)->exists())->toBeFalse();

    // The account is the tombstone account deletion leaves.
    $bot->refresh();
    expect($bot->status)->toBe(UserStatus::Deleted)
        ->and($bot->anonymized_at)->not->toBeNull()
        ->and($bot->email)->toEndWith('@deleted.invalid');
});

it('keeps every account the cleanup is not for', function (Closure $make) {
    $user = $make();
    $post = Post::factory()->published()->for($user)->create();

    expect(purgeUnconfirmed($user))->toBe(UnconfirmedAccountPurgeOutcome::NotEligible)
        ->and(app(UnconfirmedAccountsQuery::class)->expired(UnconfirmedAccountsQuery::cutoff())->whereKey($user->id)->exists())->toBeFalse()
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue()
        ->and($user->fresh()->anonymized_at)->toBeNull();
})->with([
    'a confirmed member' => [fn (): User => User::factory()->create(['created_at' => now()->subDays(30)])],
    'a member who confirmed once and has changed the address since' => [function (): User {
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);
        $user->forceFill(['email' => 'changed@example.com', 'email_verified_at' => null])->save();

        return $user;
    }],
    'a Google sign-in the provider did not vouch for' => [fn (): User => SocialAccount::factory()
        ->unverifiedEmail('someone@example.com')
        ->create(['user_id' => unconfirmedMember()])
        ->user],
    'a Persona' => [fn (): User => User::factory()->persona()->create(['created_at' => now()->subDays(30)])],
    'an administrator' => [fn (): User => User::factory()->admin()->unverified()->create(['created_at' => now()->subDays(30)])],
    'a moderator' => [fn (): User => User::factory()->moderator()->unverified()->create(['created_at' => now()->subDays(30)])],
    'an account signed up six days ago' => [fn (): User => unconfirmedMember(6)],
]);

it('only tells on a dry run who would go', function () {
    $bot = unconfirmedMember();
    $post = Post::factory()->published()->for($bot)->create();

    expect(purgeUnconfirmed($bot, dryRun: true))->toBe(UnconfirmedAccountPurgeOutcome::WouldPurge)
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue()
        ->and($bot->fresh()->anonymized_at)->toBeNull();
});

it('leaves a removed account alone', function () {
    $bot = unconfirmedMember();

    purgeUnconfirmed($bot);

    expect(purgeUnconfirmed($bot))->toBe(UnconfirmedAccountPurgeOutcome::NotEligible);
});

it('keeps the first confirmation of an address through a change of address', function () {
    $user = User::factory()->unverified()->create();

    expect($user->email_first_verified_at)->toBeNull();

    $user->markEmailAsVerified();
    $confirmed = $user->fresh()->email_first_verified_at;

    $user->forceFill(['email' => 'changed@example.com', 'email_verified_at' => null])->save();

    expect($confirmed)->not->toBeNull()
        ->and($user->fresh()->email_first_verified_at?->toDateTimeString())->toBe($confirmed->toDateTimeString());
});

it('says in the confirmation mail that an unconfirmed account is deleted, and only to one that would be', function () {
    $expires = __('mail.verify.expires', ['days' => UnconfirmedAccountsQuery::GRACE_DAYS]);

    expect(renderedMail(new VerifyEmail, User::factory()->unverified()->create())['body'])->toContain($expires);

    $changed = User::factory()->create();
    $changed->forceFill(['email' => 'changed@example.com', 'email_verified_at' => null])->save();

    expect(renderedMail(new VerifyEmail, $changed)['body'])->not->toContain($expires);
});
