<?php

use App\Actions\Analytics\RecordUserActivityAction;
use App\Actions\Traffic\ConfirmAccountAttributionAction;
use App\Enums\CommentStatus;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\TrafficEvent;
use App\Enums\UserActivity;
use App\Enums\VoteType;
use App\Livewire\Comments\CommentForm;
use App\Livewire\Comments\CommentsSection;
use App\Livewire\Feed\UploadPostForm;
use App\Livewire\Games\PlayGame;
use App\Livewire\Posts\PostVoting;
use App\Livewire\Voting\RatingVoting;
use App\Models\AccountAttribution;
use App\Models\Comment;
use App\Models\GameAttempt;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Models\UserActivityDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * A member who comes to the site and does something — a new vote on a post,
 * a new answer to a rating question, a comment or a reply, a post, a game
 * started while signed in — has the day recorded, once a day with a flag for
 * each thing; on day 1 to 7 after signing up that activates a new account,
 * once, and a confirmed account's credited visit is marked without telling
 * any network. Personas, staff and Content Studio record nothing, and a
 * failure to record never spoils what the member did.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
});

/** A member who signed up on the 17th, a day before the tests' today. */
function memberSignedUpYesterday(array $attribution = []): User
{
    $member = User::factory()->create(['created_at' => '2026-11-17 09:00:00']);
    AccountAttribution::factory()->create(['user_id' => $member->id, 'signed_up_at' => '2026-11-17 09:00:00', ...$attribution]);

    return $member;
}

function recordActivity(User $user, UserActivity $activity, string $at): void
{
    app(RecordUserActivityAction::class)->handle($user, $activity, CarbonImmutable::parse($at));
}

it('records the day a member casts a new vote on a post, and not one taken back or turned', function () {
    $member = memberSignedUpYesterday();
    $voted = Post::factory()->published()->create();
    PostVote::factory()->create(['post_id' => $voted->id, 'user_id' => $member->id, 'type' => VoteType::Up]);

    // Turning an earlier vote the other way takes it back: no new vote.
    Livewire::actingAs($member)->test(PostVoting::class, ['postId' => $voted->id])->call('vote', 'down');

    expect(PostVote::query()->where('post_id', $voted->id)->exists())->toBeFalse()
        ->and(UserActivityDay::query()->exists())->toBeFalse();

    $post = Post::factory()->published()->create();
    Livewire::actingAs($member)->test(PostVoting::class, ['postId' => $post->id])->call('vote', 'up');

    expect(UserActivityDay::query()->sole())
        ->user_id->toBe($member->id)
        ->day->toBe('2026-11-18')
        ->day_number->toBe(1)
        ->voted->toBeTrue()
        ->commented->toBeFalse()
        ->posted->toBeFalse()
        ->played->toBeFalse()
        ->first_at->toDateTimeString()->toBe('2026-11-18 10:00:00');
});

it('records the day a member answers a rating question for the first time, and not a changed answer', function () {
    $member = memberSignedUpYesterday();
    $group = RatingGroup::factory()->create(['key' => 'type']);
    [$first, $second] = [RatingOption::factory()->for($group, 'group')->create(), RatingOption::factory()->for($group, 'group')->create()];
    $answered = Post::factory()->published()->create();
    RatingVote::factory()->create(['user_id' => $member->id, 'post_id' => $answered->id, 'rating_group_id' => $group->id, 'rating_option_id' => $first->id]);

    Livewire::actingAs($member)->test(RatingVoting::class, ['post' => $answered, 'groupKey' => 'type'])->call('vote', $second->id);

    expect(RatingVote::query()->where('post_id', $answered->id)->value('rating_option_id'))->toBe($second->id)
        ->and(UserActivityDay::query()->exists())->toBeFalse();

    $post = Post::factory()->published()->create();
    Livewire::actingAs($member)->test(RatingVoting::class, ['post' => $post, 'groupKey' => 'type'])->call('vote', $first->id);

    expect(UserActivityDay::query()->sole())->voted->toBeTrue()->day_number->toBe(1);
});

it('records the day a member comments, and the day they reply', function () {
    $member = memberSignedUpYesterday();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($member)->test(CommentForm::class, ['postId' => $post->id])->set('body', 'A comment')->call('submit')->assertHasNoErrors();

    expect(UserActivityDay::query()->sole())->commented->toBeTrue()->voted->toBeFalse();

    $replier = memberSignedUpYesterday();
    $comment = Comment::factory()->for($post)->create(['status' => CommentStatus::Visible]);

    Livewire::actingAs($replier)->test(CommentsSection::class, ['postId' => $post->id])
        ->call('startReply', $comment->id)->set('replyBody', 'A reply')->call('submitReply')->assertHasNoErrors();

    expect(Comment::query()->where('user_id', $replier->id)->where('parent_id', $comment->id)->exists())->toBeTrue()
        ->and(UserActivityDay::query()->where('user_id', $replier->id)->sole())->commented->toBeTrue();
});

it('records the day a member posts', function () {
    Storage::fake('public');
    $member = memberSignedUpYesterday();

    Livewire::actingAs($member)->test(UploadPostForm::class)
        ->set('title', 'Sample Entry')->set('image', UploadedFile::fake()->image('dish.jpg'))
        ->call('submit')->assertDispatched('post-uploaded');

    expect(Post::query()->where('user_id', $member->id)->exists())->toBeTrue()
        ->and(UserActivityDay::query()->sole())->posted->toBeTrue();
});

it('records the day a signed-in member starts a game', function () {
    $draft = publishableGameDraft(1);
    liveGame($draft);
    $member = memberSignedUpYesterday();

    Livewire::actingAs($member)->test(PlayGame::class, ['slug' => $draft['game']->slug])->call('init')->call('continue');

    expect(GameAttempt::query()->sole()->user_id)->toBe($member->id)
        ->and(UserActivityDay::query()->sole())->user_id->toBe($member->id)->played->toBeTrue()->day_number->toBe(1);
});

it('records nothing for an anonymous player starting a game', function () {
    $draft = publishableGameDraft(1);
    liveGame($draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('a', 40))
        ->test(PlayGame::class, ['slug' => $draft['game']->slug])->call('init')->call('continue');

    expect(GameAttempt::query()->sole()->user_id)->toBeNull()
        ->and(UserActivityDay::query()->exists())->toBeFalse();
});

it('records nothing for a Persona or a member of staff', function () {
    $post = Post::factory()->published()->create();
    $moderator = User::factory()->moderator()->create();

    Livewire::actingAs($moderator)->test(PostVoting::class, ['postId' => $post->id])->call('vote', 'up');
    recordActivity(User::factory()->persona()->create(), UserActivity::Commented, '2026-11-18 10:00:00');
    recordActivity(User::factory()->admin()->create(), UserActivity::Posted, '2026-11-18 10:00:00');

    expect(PostVote::query()->where('user_id', $moderator->id)->exists())->toBeTrue()
        ->and(UserActivityDay::query()->exists())->toBeFalse();
});

it('records nothing for the votes Content Studio casts', function () {
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    switchStudioExecution(true);
    // A plan of the past: its post and every vote on it due at once.
    $prepared = approvableStudioPlanWithVotes(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-11-01 10:00:00')]);
    approveStudioPlan(User::factory()->admin()->create(), $prepared['plan']);

    for ($round = 0; $round < 10 && runStudioRound() > 0; $round++) {
        // Until the dispatcher has nothing more to send.
    }

    expect(PostVote::query()->count())->toBe(2)
        ->and(RatingVote::query()->count())->toBe(2)
        ->and(UserActivityDay::query()->exists())->toBeFalse();
});

it('keeps one row a day, the flags of everything done on it together and the moment of the first', function () {
    $member = memberSignedUpYesterday();

    recordActivity($member, UserActivity::Voted, '2026-11-18 08:00:00');
    recordActivity($member, UserActivity::Commented, '2026-11-18 09:00:00');
    recordActivity($member, UserActivity::Voted, '2026-11-18 11:00:00');
    recordActivity($member, UserActivity::Played, '2026-11-18 23:59:59');
    recordActivity($member, UserActivity::Posted, '2026-11-19 00:00:00');

    $days = UserActivityDay::query()->orderBy('day')->get();

    expect($days)->toHaveCount(2)
        ->and($days[0])->day->toBe('2026-11-18')->day_number->toBe(1)
        ->voted->toBeTrue()->commented->toBeTrue()->played->toBeTrue()->posted->toBeFalse()
        ->first_at->toDateTimeString()->toBe('2026-11-18 08:00:00')
        ->and($days[1])->day->toBe('2026-11-19')->day_number->toBe(2)
        ->posted->toBeTrue()->voted->toBeFalse()
        ->first_at->toDateTimeString()->toBe('2026-11-19 00:00:00');
});

it('numbers the days of an account the tracker never credited from when it was made, and activates nothing', function () {
    $member = User::factory()->create(['created_at' => '2026-11-15 23:00:00']);

    recordActivity($member, UserActivity::Voted, '2026-11-18 10:00:00');

    expect(UserActivityDay::query()->sole()->day_number)->toBe(3)
        ->and(AccountAttribution::query()->exists())->toBeFalse();
});

it('activates a new account on day 1 to 7 after the day it signed up, never on that day or the eighth', function (string $at, bool $activates) {
    $member = memberSignedUpYesterday(['signed_up_at' => '2026-11-17 23:50:00']);

    recordActivity($member, UserActivity::Voted, $at);

    expect(AccountAttribution::query()->sole()->activated_at?->toDateTimeString())->toBe($activates ? CarbonImmutable::parse($at)->toDateTimeString() : null);
})->with([
    'later on the day it signed up' => ['2026-11-17 23:59:59', false],
    'ten minutes on, the next day' => ['2026-11-18 00:00:00', true],
    'the seventh day, at its last second' => ['2026-11-24 23:59:59', true],
    'the eighth day' => ['2026-11-25 00:00:00', false],
]);

it('activates a confirmed account once, and marks its credited visit without a conversion or a postback', function () {
    recordTraffic(postbacks: true);
    $visit = TrafficVisit::factory()->create(['arrived_at' => '2026-11-17 08:50:00', 'account_at' => '2026-11-17 09:30:00', 'settled_at' => '2026-11-17 09:30:00']);
    TrafficPostbackRule::factory()->create(['source_id' => $visit->source_id, 'event' => TrafficEvent::Account, 'enabled' => true]);
    $member = memberSignedUpYesterday(['confirmed_at' => '2026-11-17 09:30:00', 'credited_visit_id' => $visit->id]);

    recordActivity($member, UserActivity::Commented, '2026-11-18 10:00:00');
    recordActivity($member, UserActivity::Voted, '2026-11-19 12:00:00');

    expect(AccountAttribution::query()->sole()->activated_at->toDateTimeString())->toBe('2026-11-18 10:00:00')
        ->and($visit->fresh()->activated_at->toDateTimeString())->toBe('2026-11-18 10:00:00')
        ->and(TrafficConversion::query()->where('visit_id', $visit->id)->exists())->toBeFalse()
        ->and(TrafficPostback::query()->exists())->toBeFalse();
});

it('activates an account not confirmed yet without marking its visit, and marks the visit when it is confirmed', function () {
    $visit = TrafficVisit::factory()->unpaid()->create(['arrived_at' => '2026-11-17 08:50:00']);
    $member = memberSignedUpYesterday(['credited_visit_id' => $visit->id]);

    recordActivity($member, UserActivity::Played, '2026-11-18 10:00:00');

    expect(AccountAttribution::query()->sole()->activated_at->toDateTimeString())->toBe('2026-11-18 10:00:00')
        ->and($visit->fresh()->activated_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00'));
    app(ConfirmAccountAttributionAction::class)->handle($member->id, CarbonImmutable::now());

    $visit->refresh();

    expect($visit->account_at->toDateTimeString())->toBe('2026-11-19 12:00:00')
        // The moment it was activated, not the moment it was confirmed.
        ->and($visit->activated_at->toDateTimeString())->toBe('2026-11-18 10:00:00')
        ->and(TrafficConversion::query()->where('visit_id', $visit->id)->where('event', TrafficEvent::Activated)->exists())->toBeFalse();
});

it('confirms an account never activated without an activation on its visit', function () {
    $visit = TrafficVisit::factory()->unpaid()->create(['arrived_at' => '2026-11-17 08:50:00']);
    $member = memberSignedUpYesterday(['credited_visit_id' => $visit->id]);

    app(ConfirmAccountAttributionAction::class)->handle($member->id, CarbonImmutable::now());

    expect($visit->fresh())->account_at->not->toBeNull()->activated_at->toBeNull();
});

it('keeps the vote when the day cannot be recorded, and reports why', function () {
    Exceptions::fake();
    $member = memberSignedUpYesterday();
    $post = Post::factory()->published()->create();
    $failing = true;
    DB::listen(function (QueryExecuted $query) use (&$failing): void {
        if ($failing && str_contains($query->sql, 'user_activity_days')) {
            $failing = false;

            throw new RuntimeException('The activity days cannot be written.');
        }
    });

    Livewire::actingAs($member)->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', 'up')
        ->assertDispatched('post-voted', postId: $post->id)
        ->assertSet('error', '');

    expect(PostVote::query()->where('post_id', $post->id)->where('user_id', $member->id)->exists())->toBeTrue()
        ->and(UserActivityDay::query()->exists())->toBeFalse();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The activity days cannot be written.');
});
