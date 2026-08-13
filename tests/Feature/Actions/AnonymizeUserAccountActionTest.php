<?php

use App\Actions\Moderation\BanUserAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Follow;
use App\Models\MediaAsset;
use App\Models\PasswordResetToken;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\Report;
use App\Models\Session;
use App\Models\User;
use App\Services\Media\MediaReferenceChecker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Builds the rich fixture graph from the PR-B contract: a user with an
 * avatar, posts (with images), comments on own and foreign posts, foreign
 * replies under their comments, votes of every kind, reports, follows in
 * both directions, saves, notifications, sessions and a reset token.
 *
 * @return array{user: User, other: User, avatar: MediaAsset, postImage: MediaAsset, ownPost: Post, foreignPost: Post, ownComment: Comment, foreignReply: Comment}
 */
function buildAnonymizationGraph(): array
{
    $user = User::factory()->create([
        'display_name' => 'Original Display',
        'bio' => 'Original bio',
        'profile_website_url' => 'https://original.example',
    ]);
    $other = User::factory()->create();

    $avatar = MediaAsset::factory()->avatar()->create();
    $user->update(['avatar_asset_id' => $avatar->id]);

    $postImage = MediaAsset::factory()->postImage()->create();
    $ownPost = Post::factory()->published()->for($user)->create(['image_asset_id' => $postImage->id]);
    $foreignPost = Post::factory()->published()->for($other)->create();

    Comment::factory()->for($user)->create(['post_id' => $ownPost->id, 'status' => CommentStatus::Visible]);
    $ownComment = Comment::factory()->for($user)->create(['post_id' => $foreignPost->id, 'status' => CommentStatus::Visible]);
    $foreignReply = Comment::factory()->for($other)->create([
        'post_id' => $foreignPost->id,
        'parent_id' => $ownComment->id,
        'status' => CommentStatus::Visible,
    ]);

    PostVote::create(['user_id' => $user->id, 'post_id' => $foreignPost->id, 'type' => VoteType::Up]);
    CommentVote::create(['user_id' => $user->id, 'comment_id' => $foreignReply->id, 'type' => VoteType::Up]);
    RatingVote::factory()->create(['user_id' => $user->id, 'post_id' => $foreignPost->id]);

    Report::create([
        'reporter_id' => $user->id,
        'target_type' => Post::class,
        'target_id' => $foreignPost->id,
        'reason' => ReportReason::Spam,
        'status' => ReportStatus::Open,
    ]);

    foreach (User::factory()->count(5)->create() as $follower) {
        Follow::create(['follower_id' => $follower->id, 'author_id' => $user->id]);
    }
    foreach (User::factory()->count(3)->create() as $followed) {
        Follow::create(['follower_id' => $user->id, 'author_id' => $followed->id]);
    }

    PostSave::create(['user_id' => $user->id, 'post_id' => $foreignPost->id]);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\FollowedAuthorPostedNotification',
        'data' => ['message' => 'fixture'],
    ]);

    Session::create([
        'id' => Str::random(40),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'fixture',
        'payload' => base64_encode('fixture'),
        'last_activity' => now()->getTimestamp(),
    ]);

    PasswordResetToken::create([
        'email' => $user->email,
        'token' => Hash::make('fixture-token'),
        'created_at' => now(),
    ]);

    return [
        'user' => $user->fresh(),
        'other' => $other,
        'avatar' => $avatar,
        'postImage' => $postImage,
        'ownPost' => $ownPost,
        'foreignPost' => $foreignPost,
        'ownComment' => $ownComment,
        'foreignReply' => $foreignReply,
    ];
}

it('tombstones the user while preserving all community contribution', function () {
    $g = buildAnonymizationGraph();
    $user = $g['user'];
    $oldEmail = $user->email;
    $oldUsername = $user->username;
    $oldPasswordHash = $user->password;

    app(AnonymizeUserAccountAction::class)->execute($user);

    $fresh = $user->fresh();

    // The row remains, permanently disabled and anonymized.
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(UserStatus::Deleted)
        ->and($fresh->anonymized_at)->not->toBeNull()
        ->and($fresh->name)->toBe('Deleted user')
        ->and($fresh->display_name)->toBeNull()
        ->and($fresh->bio)->toBeNull()
        ->and($fresh->profile_website_url)->toBeNull()
        ->and($fresh->email_verified_at)->toBeNull()
        ->and($fresh->remember_token)->toBeNull()
        ->and($fresh->role)->toBe(UserRole::User)
        ->and($fresh->trust_level)->toBe(0)
        ->and($fresh->avatar_asset_id)->toBeNull();

    // No trace of the original identity, and credentials are unusable.
    expect($fresh->email)->not->toBe($oldEmail)
        ->and($fresh->email)->toEndWith('@deleted.invalid')
        ->and($fresh->email)->not->toContain($oldEmail)
        ->and($fresh->username)->toStartWith('deleted_')
        ->and($fresh->username)->not->toContain($oldUsername)
        ->and($fresh->password)->not->toBe($oldPasswordHash)
        ->and(User::query()->where('email', $oldEmail)->exists())->toBeFalse()
        ->and(User::query()->where('username', $oldUsername)->exists())->toBeFalse();

    // Community contribution survives untouched.
    expect(Post::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($g['ownPost']->fresh()->user_id)->toBe($user->id)
        ->and(Comment::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and($g['foreignReply']->fresh())->not->toBeNull()
        ->and(PostVote::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(CommentVote::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(RatingVote::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Report::query()->where('reporter_id', $user->id)->count())->toBe(1);

    // Private/social account state is gone.
    expect(Follow::query()->where('follower_id', $user->id)->orWhere('author_id', $user->id)->count())->toBe(0)
        ->and(PostSave::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and($fresh->notifications()->count())->toBe(0)
        ->and(Session::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(PasswordResetToken::query()->where('email', $oldEmail)->exists())->toBeFalse();

    // Avatar released through the media lifecycle; post image untouched.
    expect(MediaAsset::withTrashed()->find($g['avatar']->id)->trashed())->toBeTrue()
        ->and(MediaAsset::find($g['postImage']->id)->trashed())->toBeFalse();
});

it('does not delete follow rows between unrelated users', function () {
    $g = buildAnonymizationGraph();
    $bystanderA = User::factory()->create();
    $bystanderB = User::factory()->create();
    Follow::create(['follower_id' => $bystanderA->id, 'author_id' => $bystanderB->id]);

    app(AnonymizeUserAccountAction::class)->execute($g['user']);

    expect(Follow::query()->where('follower_id', $bystanderA->id)->where('author_id', $bystanderB->id)->exists())->toBeTrue();
});

it('produces unique non-identifying tombstone identities at scale', function () {
    $users = User::factory()->count(3)->create();
    $oldEmails = $users->pluck('email')->all();

    foreach ($users as $user) {
        app(AnonymizeUserAccountAction::class)->execute($user);
    }

    $tombstones = User::query()->whereIn('id', $users->modelKeys())->get();

    expect($tombstones->pluck('email')->unique())->toHaveCount(3)
        ->and($tombstones->pluck('username')->unique())->toHaveCount(3);

    foreach ($tombstones as $i => $tombstone) {
        expect($tombstone->email)->toEndWith('@deleted.invalid')
            ->and($tombstone->email)->not->toContain($oldEmails[$i]);
    }
});

it('is idempotent: a second call never re-anonymizes or double-releases', function () {
    $g = buildAnonymizationGraph();
    $user = $g['user'];

    app(AnonymizeUserAccountAction::class)->execute($user);

    $firstUsername = $user->fresh()->username;
    $firstEmail = $user->fresh()->email;
    $firstAnonymizedAt = $user->fresh()->anonymized_at;
    $avatarDeletedAt = MediaAsset::withTrashed()->find($g['avatar']->id)->deleted_at;

    app(AnonymizeUserAccountAction::class)->execute($user->fresh());

    $fresh = $user->fresh();
    expect($fresh->username)->toBe($firstUsername)
        ->and($fresh->email)->toBe($firstEmail)
        ->and($fresh->anonymized_at->equalTo($firstAnonymizedAt))->toBeTrue()
        ->and(Post::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Comment::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(PostVote::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Report::query()->where('reporter_id', $user->id)->count())->toBe(1)
        ->and(MediaAsset::withTrashed()->find($g['avatar']->id)->deleted_at->equalTo($avatarDeletedAt))->toBeTrue();
});

it('rolls back to a fully intact account if anonymization fails mid-flight', function () {
    $g = buildAnonymizationGraph();
    $user = $g['user'];
    $oldEmail = $user->email;
    $oldUsername = $user->username;
    $oldPasswordHash = $user->password;

    // The media release is the last DB step inside the transaction —
    // failing there proves every earlier mutation (identity, follows,
    // saves, notifications, sessions, tokens) rolls back with it.
    $originalChecker = app(MediaReferenceChecker::class);

    app()->instance(MediaReferenceChecker::class, new class extends MediaReferenceChecker
    {
        public function referencedAssetIds(Collection $assetIds): Collection
        {
            throw new RuntimeException('Simulated media release failure.');
        }
    });

    try {
        expect(fn () => app(AnonymizeUserAccountAction::class)->execute($user))
            ->toThrow(RuntimeException::class, 'Simulated media release failure.');
    } finally {
        app()->instance(MediaReferenceChecker::class, $originalChecker);
    }

    $fresh = $user->fresh();
    expect($fresh->status)->toBe(UserStatus::Active)
        ->and($fresh->anonymized_at)->toBeNull()
        ->and($fresh->email)->toBe($oldEmail)
        ->and($fresh->username)->toBe($oldUsername)
        ->and($fresh->password)->toBe($oldPasswordHash)
        ->and($fresh->avatar_asset_id)->toBe($g['avatar']->id)
        ->and(Follow::query()->where('follower_id', $user->id)->orWhere('author_id', $user->id)->count())->toBe(8)
        ->and(PostSave::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($fresh->notifications()->count())->toBe(1)
        ->and(Session::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(PasswordResetToken::query()->where('email', $oldEmail)->exists())->toBeTrue()
        ->and(MediaAsset::find($g['avatar']->id)->trashed())->toBeFalse();
});

it('anonymizes from the re-read locked row, not the caller\'s stale instance', function () {
    $user = User::factory()->create();
    $stale = User::query()->findOrFail($user->id);

    // Concurrent moderation limited the account after our instance loaded.
    User::query()->whereKey($user->id)->update(['status' => UserStatus::Limited->value]);

    app(AnonymizeUserAccountAction::class)->execute($stale);

    expect($user->fresh()->status)->toBe(UserStatus::Deleted);
    // The passed instance was synchronized with the committed tombstone.
    expect($stale->status)->toBe(UserStatus::Deleted)
        ->and($stale->anonymized_at)->not->toBeNull();
});

it('strips privileges when an admin or moderator account is anonymized', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();

    app(AnonymizeUserAccountAction::class)->execute($user);

    $fresh = $user->fresh();
    expect($fresh->role)->toBe(UserRole::User)
        ->and($fresh->status)->toBe(UserStatus::Deleted)
        ->and($fresh->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeFalse();
})->with(['admin', 'moderator']);

it('keeps a shared former avatar active when someone else still references it', function () {
    $shared = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $shared->id]);
    User::factory()->create(['avatar_asset_id' => $shared->id]);

    app(AnonymizeUserAccountAction::class)->execute($user);

    expect(MediaAsset::find($shared->id)->trashed())->toBeFalse();
});

it('completes without error for a user with no avatar and no private state', function () {
    $user = User::factory()->create(['avatar_asset_id' => null]);

    app(AnonymizeUserAccountAction::class)->execute($user);

    expect($user->fresh()->status)->toBe(UserStatus::Deleted);
});

/*
 * Banned and Deleted are distinct states: a ban is a reversible
 * participation restriction that keeps the whole identity; deletion is an
 * irreversible anonymization that keeps only the contribution.
 */
it('keeps identity, avatar and follows intact for banned users, unlike deleted ones', function () {
    $banned = User::factory()->create(['avatar_asset_id' => MediaAsset::factory()->avatar()->create()->id]);
    $deleted = User::factory()->create(['avatar_asset_id' => MediaAsset::factory()->avatar()->create()->id]);
    $bannedName = $banned->name;
    $observer = User::factory()->create();
    Follow::create(['follower_id' => $observer->id, 'author_id' => $banned->id]);
    Follow::create(['follower_id' => $observer->id, 'author_id' => $deleted->id]);
    Post::factory()->published()->for($banned)->create();
    Post::factory()->published()->for($deleted)->create();

    $admin = User::factory()->admin()->create();
    app(BanUserAction::class)->handle($admin, $banned);
    app(AnonymizeUserAccountAction::class)->execute($deleted);

    $bannedFresh = $banned->fresh();
    expect($bannedFresh->name)->toBe($bannedName)
        ->and($bannedFresh->avatar_asset_id)->not->toBeNull()
        ->and($bannedFresh->anonymized_at)->toBeNull()
        ->and(Follow::query()->where('author_id', $banned->id)->count())->toBe(1);

    $deletedFresh = $deleted->fresh();
    expect($deletedFresh->name)->toBe('Deleted user')
        ->and($deletedFresh->avatar_asset_id)->toBeNull()
        ->and(Follow::query()->where('author_id', $deleted->id)->count())->toBe(0);

    // Contribution survives in both states.
    expect(Post::query()->where('user_id', $banned->id)->count())->toBe(1)
        ->and(Post::query()->where('user_id', $deleted->id)->count())->toBe(1);
});

it('refuses to ban, shadowban or mark trusted a deleted tombstone', function () {
    $admin = User::factory()->admin()->create();
    $tombstone = User::factory()->tombstoned()->create();

    expect($admin->can('ban', $tombstone))->toBeFalse()
        ->and($admin->can('shadowban', $tombstone))->toBeFalse()
        ->and($admin->can('unban', $tombstone))->toBeFalse()
        ->and($admin->can('manage', $tombstone))->toBeFalse()
        ->and($admin->can('markTrusted', $tombstone))->toBeFalse();
});
