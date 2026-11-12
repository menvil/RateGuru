<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Follows\FollowAuthorAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Rating\VoteRatingOptionAction;
use App\Actions\Reports\ReportContentAction;
use App\Actions\Votes\VoteCommentAction;
use App\Actions\Votes\VotePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Enums\VoteType;
use App\Exceptions\Comments\CannotCommentException;
use App\Exceptions\Follows\CannotFollowAuthorException;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Exceptions\Votes\CannotVoteException;
use App\Livewire\Comments\CommentForm;
use App\Livewire\Feed\UploadPostForm;
use App\Livewire\Follows\FollowButton;
use App\Models\Comment;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingOption;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\User;
use Livewire\Livewire;

/*
 * An account that signed up with an email address takes part once it has
 * confirmed one (docs/architecture/user-lifecycle.md, "Unconfirmed
 * accounts"). A Google or Facebook sign-in, staff and Personas never need to.
 */

it('refuses an account that has not confirmed its address every participation but reporting', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $user = User::factory()->unverified()->create();
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create();
    $comment = Comment::factory()->for($author)->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);
    $option = RatingOption::factory()->create();

    $refusals = [
        CannotCreatePostException::class => fn () => app(CreatePostAction::class)->handle($user, new CreatePostData(title: 'Held back')),
        CannotCommentException::class => fn () => app(AddCommentAction::class)->handle($user, $post, 'Held back'),
        CannotVoteException::class => fn () => app(VotePostAction::class)->handle($user, $post, VoteType::Up),
        CannotVoteCommentException::class => fn () => app(VoteCommentAction::class)->handle($user, $comment, VoteType::Up),
        CannotVoteForRatingOptionException::class => fn () => app(VoteRatingOptionAction::class)->handle($user, $post, $option),
        CannotFollowAuthorException::class => fn () => app(FollowAuthorAction::class)->handle($user, $author),
    ];

    foreach ($refusals as $exception => $attempt) {
        expect($attempt)->toThrow($exception);
    }

    app(ReportContentAction::class)->handle($user, $post, ReportReason::Spam);

    expect(Report::query()->where('reporter_id', $user->id)->count())->toBe(1)
        ->and($user->needsAddressConfirmation())->toBeTrue();
});

it('lets an account take part once it confirms its address', function () {
    $user = User::factory()->unverified()->create();
    $post = Post::factory()->published()->create();

    $user->markEmailAsVerified();

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);

    expect($post->postVotes()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($user->needsAddressConfirmation())->toBeFalse();
});

it('never asks a confirmed address of a Google or Facebook sign-in, staff or a Persona', function (Closure $make) {
    $user = $make();

    expect($user->email_verified_at)->toBeNull()
        ->and($user->hasConfirmedIdentity())->toBeTrue()
        ->and($user->canVote())->toBeTrue()
        ->and($user->canComment())->toBeTrue()
        ->and($user->canCreateContent())->toBeTrue()
        ->and($user->canFollow())->toBeTrue()
        ->and($user->needsAddressConfirmation())->toBeFalse();
})->with([
    'a Facebook sign-in the provider did not vouch for' => [fn (): User => SocialAccount::factory()
        ->unverifiedEmail('someone@example.com')
        ->create(['user_id' => User::factory()->unverified()])
        ->user],
    'a moderator' => [fn (): User => User::factory()->moderator()->unverified()->create()],
    'an administrator' => [fn (): User => User::factory()->admin()->unverified()->create()],
    'a Persona' => [fn (): User => User::factory()->persona()->create()],
]);

it('says a sanctioned account is sanctioned, not that its address waits', function () {
    $user = User::factory()->limited()->unverified()->create();

    expect($user->canVote())->toBeFalse()
        ->and($user->needsAddressConfirmation())->toBeFalse();
});

it('tells an unconfirmed account on every page what it may not do and where the link went', function () {
    $user = User::factory()->unverified()->create(['email' => 'new@example.com']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('data-testid="account-confirmation-notice"', false)
        ->assertSeeText(__('ui.account_confirmation.notice', ['email' => 'new@example.com', 'days' => 7]))
        ->assertSee('data-testid="account-confirmation-resend"', false);

    $this->actingAs($user)
        ->withSession(['status' => 'verification-link-sent'])
        ->get(route('profile.edit'))
        ->assertSee('data-testid="account-confirmation-sent"', false)
        ->assertDontSee('data-testid="account-confirmation-resend"', false);
});

it('shows the notice to nobody who need not confirm an address', function (Closure $make) {
    $this->actingAs($make())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('data-testid="account-confirmation-notice"', false);
})->with([
    'a confirmed member' => [fn (): User => User::factory()->create()],
    'a sanctioned member' => [fn (): User => User::factory()->limited()->unverified()->create()],
    'an administrator' => [fn (): User => User::factory()->admin()->unverified()->create()],
]);

it('says to confirm the address where a click is refused', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true, 'allow_user_uploads' => true]]);

    $user = User::factory()->unverified()->create();
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create();
    $required = __('ui.account_confirmation.required');

    $this->actingAs($user);

    Livewire::test(CommentForm::class, ['postId' => $post->id])
        ->set('body', 'Held back')
        ->call('submit')
        ->assertHasErrors(['body'])
        ->assertSee($required);

    Livewire::test(FollowButton::class, ['author' => $author])
        ->call('toggle')
        ->assertSet('message', $required);

    Livewire::test(UploadPostForm::class)
        ->assertSeeHtml('data-testid="upload-confirmation-required"')
        ->assertDontSeeHtml('data-testid="upload-form"')
        ->set('title', 'Held back')
        ->call('submit')
        ->assertSet('submitError', $required);

    expect(Comment::query()->count())->toBe(0)
        ->and(Post::query()->count())->toBe(1);
});
