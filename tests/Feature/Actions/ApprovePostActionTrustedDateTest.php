<?php

use App\Actions\Moderation\ApprovePostAction;
use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * ApprovePostAction's trusted publication date, which only Content Studio's
 * executor gives, and its job for followers, sent only once the outermost
 * transaction commits — never for an approval that was taken back.
 */
beforeEach(function () {
    // The database queue: it sends a job dispatched after the commit only once the transaction commits.
    config(['queue.default' => 'database']);
    $this->queued = [];
    Event::listen(JobQueued::class, function (JobQueued $event): void {
        $this->queued[] = is_object($event->job) ? $event->job::class : (string) $event->job;
    });
});

afterEach(function () {
    Carbon::setTestNow();
});

it('publishes now when no date is given, as every moderator\'s approval does', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $post = Post::factory()->create(['status' => PostStatus::Pending, 'published_at' => null]);

    app(ApprovePostAction::class)->handle(User::factory()->admin()->create(), $post);

    expect($post->fresh())->status->toBe(PostStatus::Published)
        ->and($post->fresh()->published_at->toDateTimeString())->toBe('2026-10-20 10:00:00');
});

it('publishes on the date it is given, with the moderation log and the author\'s notification as ever', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->create(['status' => PostStatus::Pending, 'published_at' => null]);

    app(ApprovePostAction::class)->handle($admin, $post, null, CarbonImmutable::parse('2021-01-01 12:00:00'));

    expect($post->fresh()->published_at->toDateTimeString())->toBe('2021-01-01 12:00:00')
        ->and(ModerationLog::query()->where('action', ModerationActionType::ApprovePost)->sole())
        ->moderator_id->toBe($admin->id)
        // The log and the notification keep the real time.
        ->and(ModerationLog::query()->sole()->created_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($post->user->notifications()->where('type', PostApprovedNotification::class)->sole()->created_at->toDateTimeString())->toBe('2026-10-20 10:00:00');
});

it('never moves a publication date the post already has', function () {
    $post = Post::factory()->create(['status' => PostStatus::Pending, 'published_at' => '2019-05-06 07:08:00']);

    app(ApprovePostAction::class)->handle(User::factory()->admin()->create(), $post, null, CarbonImmutable::parse('2021-01-01 12:00:00'));

    expect($post->fresh()->published_at->toDateTimeString())->toBe('2019-05-06 07:08:00');
});

it('lets no one approve who may not, a date given or not', function () {
    $post = Post::factory()->create(['status' => PostStatus::Pending]);

    expect(fn () => app(ApprovePostAction::class)->handle(User::factory()->create(), $post, null, CarbonImmutable::parse('2021-01-01 12:00:00')))
        ->toThrow(fn (CannotModeratePostException $refusal) => expect($refusal->isNotAllowed())->toBeTrue());

    expect($post->fresh()->status)->toBe(PostStatus::Pending);
});

it('sends the followers\' job only once the outermost transaction commits, and never for one rolled back', function () {
    $admin = User::factory()->admin()->create();
    [$kept, $taken] = Post::factory()->count(2)->create(['status' => PostStatus::Pending]);

    DB::transaction(function () use ($admin, $kept): void {
        app(ApprovePostAction::class)->handle($admin, $kept);

        expect($this->queued)->not->toContain(NotifyFollowersAboutNewPostJob::class);
    });

    expect($this->queued)->toContain(NotifyFollowersAboutNewPostJob::class);
    $this->queued = [];

    DB::beginTransaction();
    app(ApprovePostAction::class)->handle($admin, $taken);
    DB::rollBack();

    expect($this->queued)->not->toContain(NotifyFollowersAboutNewPostJob::class)
        ->and($taken->fresh()->status)->toBe(PostStatus::Pending);
});

it('still sends the followers\' job at once outside any transaction', function () {
    $post = Post::factory()->create(['status' => PostStatus::Pending]);

    app(ApprovePostAction::class)->handle(User::factory()->admin()->create(), $post);

    expect($this->queued)->toContain(NotifyFollowersAboutNewPostJob::class);
});
