<?php

use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\LimitUserAction;
use App\Actions\Moderation\RestoreUserAccessAction;
use App\Actions\Moderation\ShadowbanUserAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\SavePostAction;
use App\Actions\Posts\UnsavePostAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Reports\ReportContentAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\ReportReason;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\Comment;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\PostVote;
use App\Models\ProjectSettings;
use App\Models\Report;
use App\Models\User;
use Filament\Panel;
use Illuminate\Support\Str;

/*
 * PR-F semantics: MODERATE USER != DELETE ACCOUNT != MODERATE CONTENT.
 * A sanction changes participation rights only.
 */

it('changes only status and one moderation log on sanction, preserving the entire private graph', function (string $action, UserStatus $expected) {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->withAvatar()->create();

    $post = Post::factory()->published()->for($user)->create();
    Comment::factory()->for($user)->create(['post_id' => $post->id]);
    PostVote::create(['user_id' => $user->id, 'post_id' => Post::factory()->published()->create()->id, 'type' => 'up']);
    PostSave::create(['user_id' => $user->id, 'post_id' => $post->id]);
    Report::factory()->create(['reporter_id' => $user->id]);
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\PostCommentedNotification',
        'data' => ['message' => 'private state'],
        'read_at' => null,
    ]);

    $before = $user->fresh();

    app($action)->handle($admin, $user);

    $after = $user->fresh();

    expect($after->status)->toBe($expected)
        ->and($after->anonymized_at)->toBeNull()
        ->and($after->name)->toBe($before->name)
        ->and($after->email)->toBe($before->email)
        ->and($after->avatar_asset_id)->toBe($before->avatar_asset_id)
        ->and($after->role)->toBe($before->role)
        ->and((int) $after->trust_level)->toBe((int) $before->trust_level)
        ->and($after->posts()->count())->toBe(1)
        ->and($after->comments()->count())->toBe(1)
        ->and(PostVote::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(PostSave::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Report::query()->where('reporter_id', $user->id)->count())->toBe(1)
        ->and($after->notifications()->count())->toBe(1)
        ->and(ModerationLog::query()->count())->toBe(1);
})->with([
    'limit' => [LimitUserAction::class, UserStatus::Limited],
    'ban' => [BanUserAction::class, UserStatus::Banned],
    'shadowban' => [ShadowbanUserAction::class, UserStatus::Shadowbanned],
]);

it('keeps sanctioned authors content publicly visible with no lifecycle change', function () {
    $admin = User::factory()->admin()->create();
    $author = User::factory()->create();

    $post = Post::factory()->published()->for($author)->create();
    $comment = Comment::factory()->for($author)->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);

    // PR-E state that must not move: an author-deleted post keeps its
    // retention clock exactly as it was.
    $deletedPost = Post::factory()->published()->for($author)->create();
    app(DeletePostAction::class)->handle($author, $deletedPost);
    $deletedAt = Post::withTrashed()->findOrFail($deletedPost->id)->deleted_at;

    app(BanUserAction::class)->handle($admin, $author->fresh());

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and($comment->fresh()->status)->toBe(CommentStatus::Visible)
        ->and($comment->fresh()->trashed())->toBeFalse();

    $retained = Post::withTrashed()->findOrFail($deletedPost->id);
    expect($retained->status)->toBe(PostStatus::Deleted)
        ->and($retained->deleted_at->equalTo($deletedAt))->toBeTrue()
        ->and($retained->deleted_from_status)->toBe(PostStatus::Published);

    // Public profile stays reachable.
    $this->get(route('profile.show', $author->fresh()->username))->assertOk();
});

it('lets a sanctioned user keep private save/unsave, subject to post lifecycle', function (string $state) {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->{$state}()->create();
    $post = Post::factory()->published()->create();

    app(SavePostAction::class)->handle($user, $post);
    expect(PostSave::query()->where('user_id', $user->id)->count())->toBe(1);

    app(UnsavePostAction::class)->handle($user, $post);
    expect(PostSave::query()->where('user_id', $user->id)->count())->toBe(0);
})->with(['limited', 'banned', 'shadowbanned']);

it('denies the privileged panel to a sanctioned moderator and restores it with access', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();
    $panel = Mockery::mock(Panel::class);
    $panel->shouldReceive('getId')->andReturn('admin');

    expect($moderator->canAccessPanel($panel))->toBeTrue();

    app(BanUserAction::class)->handle($admin, $moderator);

    $sanctioned = $moderator->fresh();
    expect($sanctioned->role)->toBe(UserRole::Moderator)
        ->and($sanctioned->canAccessPanel($panel))->toBeFalse();

    app(RestoreUserAccessAction::class)->handle($admin, $sanctioned);

    $restored = $moderator->fresh();
    expect($restored->role)->toBe(UserRole::Moderator)
        ->and($restored->canAccessPanel($panel))->toBeTrue();
});

it('lets every sanctioned living state self-delete through the PR-B pipeline', function (string $state) {
    $user = User::factory()->{$state}()->create();
    $post = Post::factory()->published()->for($user)->create();
    $originalName = $user->name;

    app(AnonymizeUserAccountAction::class)->execute($user);

    $fresh = $user->fresh();
    expect($fresh->status)->toBe(UserStatus::Deleted)
        ->and($fresh->anonymized_at)->not->toBeNull()
        ->and($fresh->name)->not->toBe($originalName)
        ->and($fresh->role)->toBe(UserRole::User)
        // Community content survives account deletion (PR-B).
        ->and($post->fresh()->status)->toBe(PostStatus::Published);
})->with(['limited', 'banned', 'shadowbanned']);

it('refuses new reports against a Deleted tombstone target', function () {
    $reporter = User::factory()->create();
    $tombstone = User::factory()->create();
    app(AnonymizeUserAccountAction::class)->execute($tombstone);

    expect(fn () => app(ReportContentAction::class)->handle($reporter, $tombstone->fresh(), ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
});

it('keeps living users reportable', function (string $state) {
    $reporter = User::factory()->create();
    $target = User::factory()->{$state}()->create();

    $report = app(ReportContentAction::class)->handle($reporter, $target, ReportReason::Offensive);

    expect($report->exists)->toBeTrue();
})->with(['banned', 'shadowbanned']);
