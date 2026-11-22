<?php

use App\Actions\Moderation\ConfirmUserEmailAction;
use App\Actions\Profile\UpdateUserIdentityAction;
use App\Actions\Users\UpdateUserAccountAction;
use App\Enums\ModerationActionType;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Livewire\Admin\UserEditor;
use App\Models\User;
use App\Queries\Users\UnconfirmedAccountsQuery;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
 * Getting from a sign-up to a confirmed address, whatever goes wrong on the
 * way: a link read late, a mail that does not arrive, a mistyped address.
 */

function confirmationLinkFor(User $user): string
{
    return (new VerifyEmail)->toMail($user)->actionUrl;
}

function useFailingMailServer(): void
{
    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('The mail server is down.');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });
    config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
}

it('keeps a confirmation link valid for a day', function () {
    $user = User::factory()->unverified()->create();
    $link = confirmationLinkFor($user);

    $this->travel(23)->hours();
    $this->actingAs($user)->get($link)->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('sends an expired link to the page that offers a new one', function () {
    $user = User::factory()->unverified()->create();
    $link = confirmationLinkFor($user);

    $this->travel(25)->hours();

    $this->actingAs($user)->get($link)->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))
        ->assertOk()
        ->assertSee('data-testid="verification-link-expired"', false)
        ->assertSeeText(__('auth.verify_email.link_expired'))
        ->assertSeeText(__('auth.verify_email.resend'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('takes a signed-out person with an expired link through signing in to the same page', function () {
    $user = User::factory()->unverified()->create();
    $link = confirmationLinkFor($user);

    $this->travel(25)->hours();

    $this->get($link)->assertRedirect(route('login'));
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($link);
    $this->get($link)->assertRedirect(route('verification.notice'));
});

it('leaves a bad signature on any other route an error', function () {
    Route::middleware(['web', 'signed'])->get('/signed-elsewhere', fn () => 'reached');

    $this->get('/signed-elsewhere')->assertForbidden();
});

it('says the address is confirmed on the page after the redirect through the dashboard', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(confirmationLinkFor($user));
    $this->get(route('dashboard'))->assertRedirect(route('feed'));

    $this->get(route('feed'))->assertOk()->assertSee(__('auth.verify_email.confirmed'), false);
    $this->get(route('feed'))->assertDontSee(__('auth.verify_email.confirmed'), false);
});

it('sends a new link at most once a minute, and says how long to wait', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->from(route('profile.edit'))->post(route('verification.send'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'verification-link-sent');

    $this->from(route('profile.edit'))->post(route('verification.send'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('verification_wait', __('auth.verify_email.throttled', ['minutes' => 1]));

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);

    $this->get(route('profile.edit'))
        ->assertSee('data-testid="account-confirmation-wait"', false)
        ->assertSee('data-testid="account-confirmation-resend"', false);

    $this->travel(61)->seconds();
    $this->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentToTimes($user, VerifyEmail::class, 2);
});

it('sends at most five links an hour', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    foreach (range(1, 6) as $attempt) {
        $this->post(route('verification.send'));
        $this->travel(61)->seconds();
    }

    Notification::assertSentToTimes($user, VerifyEmail::class, 5);
});

it('says the wait really left once the hour\'s links are used, even within the minute', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    foreach (range(1, 4) as $attempt) {
        $this->post(route('verification.send'));
        $this->travel(61)->seconds();
    }
    $this->post(route('verification.send'));

    $this->post(route('verification.send'))->assertSessionHas('verification_wait');

    expect(session('verification_wait'))->not->toBe(__('auth.verify_email.throttled', ['minutes' => 1]));
    Notification::assertSentToTimes($user, VerifyEmail::class, 5);
});

it('does not spend the hour on clicks the minute limit refuses', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    foreach (range(1, 8) as $click) {
        $this->post(route('verification.send'));
    }
    $this->travel(61)->seconds();
    $this->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentToTimes($user, VerifyEmail::class, 2);
});

it('keeps one account from using up the limit of another', function () {
    Notification::fake();
    $first = User::factory()->unverified()->create();
    $second = User::factory()->unverified()->create();

    $this->actingAs($first)->post(route('verification.send'));
    $this->actingAs($second)->post(route('verification.send'));

    Notification::assertSentToTimes($first, VerifyEmail::class, 1);
    Notification::assertSentToTimes($second, VerifyEmail::class, 1);
});

it('counts the mail sent on signing up towards the limit', function () {
    Notification::fake();

    $this->post('/register', [
        'name' => 'New Member',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
    $user = User::query()->where('email', 'new@example.com')->sole();

    $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('verification_wait');

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('finishes a sign-up while the mail server is down', function () {
    Exceptions::fake();
    useFailingMailServer();

    $this->post('/register', [
        'name' => 'New Member',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(User::query()->where('email', 'new@example.com')->exists())->toBeTrue();
    Exceptions::assertReported(TransportException::class);
});

it('sends a link when someone changes their address, and none when they keep it', function () {
    Notification::fake();
    $user = User::factory()->create(['username' => 'kept_user', 'email' => 'old@example.com']);
    $action = app(UpdateUserIdentityAction::class);

    $action->execute($user, ['name' => 'Renamed', 'username' => 'kept_user', 'email' => 'old@example.com']);
    Notification::assertNothingSentTo($user);

    $action->execute($user, ['name' => 'Renamed', 'username' => 'kept_user', 'email' => 'new@example.com']);

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('saves a new address even when the limit is reached or the mail server is down', function () {
    Exceptions::fake();
    useFailingMailServer();
    $user = User::factory()->create(['username' => 'kept_user', 'email' => 'old@example.com']);
    $action = app(UpdateUserIdentityAction::class);

    $action->execute($user, ['name' => 'Name', 'username' => 'kept_user', 'email' => 'second@example.com']);
    $action->execute($user, ['name' => 'Name', 'username' => 'kept_user', 'email' => 'third@example.com']);

    expect($user->fresh()->email)->toBe('third@example.com');
    Exceptions::assertReported(TransportException::class);
});

it('sends a link when an administrator changes someone\'s address', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create(['email' => 'old@example.com']);
    $drawnFrom = ['name' => $person->name, 'username' => $person->username, 'email' => 'old@example.com', 'role' => $person->role->value];

    app(UpdateUserAccountAction::class)->handle($admin, $person, [
        'name' => $person->name,
        'username' => $person->username,
        'email' => 'new@example.com',
        'role' => $person->role,
        'trusted' => null,
    ], $drawnFrom);

    Notification::assertSentToTimes($person, VerifyEmail::class, 1);
});

it('does not tell someone who changed a confirmed address that the account will be deleted', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'changed@example.com',
        'email_first_verified_at' => now()->subMonth(),
    ]);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertSeeText(__('auth.verify_email.notice_changed_address', ['email' => 'changed@example.com']))
        ->assertDontSeeText(__('ui.account_confirmation.notice', ['email' => 'changed@example.com', 'days' => 7]));
});

it('lets an administrator confirm an address, in the log and as any confirmation', function () {
    Event::fake([Verified::class]);
    $admin = User::factory()->admin()->create();
    $person = User::factory()->unverified()->create(['created_at' => now()->subDays(30)]);

    app(ConfirmUserEmailAction::class)->handle($admin, $person, (string) $person->email, 'Confirmed by support.');

    $fresh = $person->fresh();
    expect($fresh->hasVerifiedEmail())->toBeTrue()
        ->and($fresh->email_first_verified_at)->not->toBeNull()
        ->and($fresh->canVote())->toBeTrue()
        ->and(app(UnconfirmedAccountsQuery::class)->isUnconfirmed($fresh))->toBeFalse();

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::ConfirmUserEmail->value,
        'target_type' => User::class,
        'target_id' => $person->id,
        'reason' => 'Confirmed by support.',
    ]);
    Event::assertDispatched(Verified::class);
});

it('refuses to confirm an address it should not', function (Closure $actor, Closure $target) {
    $person = $target();

    expect(fn () => app(ConfirmUserEmailAction::class)->handle($actor(), $person, (string) $person->email))
        ->toThrow(CannotModerateUserException::class);

    $this->assertDatabaseMissing('moderation_logs', ['action' => ModerationActionType::ConfirmUserEmail->value]);
})->with([
    'a moderator asking' => [fn () => User::factory()->moderator()->create(), fn () => User::factory()->unverified()->create()],
    'a member asking' => [fn () => User::factory()->create(), fn () => User::factory()->unverified()->create()],
    'a Persona' => [fn () => User::factory()->admin()->create(), fn () => User::factory()->persona()->create()],
    'a staff account' => [fn () => User::factory()->admin()->create(), fn () => User::factory()->moderator()->unverified()->create()],
    'a sanctioned member' => [fn () => User::factory()->admin()->create(), fn () => User::factory()->banned()->unverified()->create()],
    'a tombstone' => [fn () => User::factory()->admin()->create(), fn () => User::factory()->tombstoned()->create(['email_verified_at' => null])],
    'an address already confirmed' => [fn () => User::factory()->admin()->create(), fn () => User::factory()->create()],
]);

it('never confirms an address other than the one the administrator was shown', function () {
    Event::fake([Verified::class]);
    $admin = User::factory()->admin()->create();
    $person = User::factory()->unverified()->create(['email' => 'shown@example.com']);
    User::query()->whereKey($person->id)->update(['email' => 'victim@example.com']);

    expect(fn () => app(ConfirmUserEmailAction::class)->handle($admin, $person, 'shown@example.com'))
        ->toThrow(CannotModerateUserException::class)
        ->and($person->fresh()->hasVerifiedEmail())->toBeFalse();

    Event::assertNotDispatched(Verified::class);
});

it('says so when the address changed while the dialog was open', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->unverified()->create(['email' => 'shown@example.com']);
    $this->actingAs($admin);

    $page = Livewire::test(UserEditor::class, ['userId' => $person->id])
        ->call('askToSanction', 'confirm_email', $person->id)
        ->assertSee('shown@example.com counts as confirmed');

    User::query()->whereKey($person->id)->update(['email' => 'victim@example.com']);

    $page->call('confirmSanction')
        ->assertDispatched('rg-admin-toast', tone: 'error');

    expect($person->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('offers the confirmation on the person\'s page, and records it in the history', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->unverified()->create(['username' => 'lost_mail']);
    $this->actingAs($admin);

    Livewire::test(UserEditor::class, ['userId' => $person->id])
        ->assertSee('data-testid="user-editor-confirm-email"', false)
        ->call('askToSanction', 'confirm_email', $person->id)
        ->assertSet('sanction', ['kind' => 'confirm_email', 'id' => $person->id, 'email' => $person->email])
        ->assertSee('Confirm the email of lost_mail?')
        ->call('confirmSanction')
        ->assertSet('sanction', null)
        ->assertDispatched('rg-admin-toast', message: 'lost_mail’s email is confirmed')
        ->assertDispatched('user-changed')
        ->assertDontSee('data-testid="user-editor-confirm-email"', false)
        ->assertSee('Email confirmed by '.$admin->name);

    expect($person->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('does not open the confirmation for an address already confirmed', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->call('askToSanction', 'confirm_email', $person->id)
        ->assertSet('sanction', null);
});
