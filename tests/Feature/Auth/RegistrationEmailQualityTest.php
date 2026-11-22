<?php

use App\Actions\Auth\ScreenEmailChangeAction;
use App\Actions\Auth\ScreenRegistrationAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Models\User;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Auth\EmailCanonicalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Monolog\LogRecord;

/*
 * Throwaway addresses and other spellings of a Gmail mailbox, at sign-up and
 * when an account moves to a new address.
 */

beforeEach(function () {
    config(['registration.screening_mode' => 'enforce']);
});

it('finds the mailbox behind a Gmail address', function (?string $email, ?string $canonical) {
    expect(EmailCanonicalizer::canonical($email))->toBe($canonical);
})->with([
    'plain' => ['ivan@gmail.com', 'ivan@gmail.com'],
    'dots' => ['i.v.a.n@gmail.com', 'ivan@gmail.com'],
    'plus' => ['ivan+spam@gmail.com', 'ivan@gmail.com'],
    'dots and plus' => ['i.van+a.b+c@gmail.com', 'ivan@gmail.com'],
    'googlemail' => ['ivan@googlemail.com', 'ivan@gmail.com'],
    'capitals and spaces' => ['  I.Van@GMail.com ', 'ivan@gmail.com'],
    'nothing before the plus' => ['+spam@gmail.com', null],
    'another provider keeps its rules' => ['i.van+x@outlook.com', null],
    'a lookalike domain' => ['ivan@gmail.com.example', null],
    'no address' => ['not-an-address', null],
    'nothing' => [null, null],
]);

it('keeps the mailbox with the address through every write', function () {
    $user = User::factory()->create(['email' => 'i.van+x@gmail.com']);
    expect($user->fresh()->email_canonical)->toBe('ivan@gmail.com');

    $user->update(['email' => 'other@example.com']);
    expect($user->fresh()->email_canonical)->toBeNull();

    $user->update(['email' => 'pe.tro@googlemail.com']);
    expect($user->fresh()->email_canonical)->toBe('petro@gmail.com');
});

it('never shows the mailbox when an account is serialized', function () {
    expect(User::factory()->create(['email' => 'ivan@gmail.com'])->fresh()->toArray())
        ->not->toHaveKey('email_canonical');
});

it('forgets the mailbox of a deleted account, freeing it for a new one', function () {
    $user = User::factory()->create(['email' => 'ivan@gmail.com']);

    app(AnonymizeUserAccountAction::class)->execute($user);

    expect($user->fresh()->email_canonical)->toBeNull();

    $this->post('/register', screenedSignUp(['email' => 'i.van@gmail.com']))->assertSessionHasNoErrors();
});

it('fills the mailbox in for the accounts already there', function () {
    $gmail = User::factory()->create(['email' => 'first@example.com']);
    $googlemail = User::factory()->create(['email' => 'second@example.com']);
    $other = User::factory()->create(['email' => 'third.person+x@example.com']);
    DB::table('users')->where('id', $gmail->id)->update(['email' => 'I.Van+old@Gmail.com', 'email_canonical' => null]);
    DB::table('users')->where('id', $googlemail->id)->update(['email' => 'pe.tro@googlemail.com', 'email_canonical' => null]);

    $migration = require database_path('migrations/2026_11_22_150000_add_email_canonical_to_users_table.php');
    $migration->backfill();

    expect($gmail->fresh()->email_canonical)->toBe('ivan@gmail.com')
        ->and($googlemail->fresh()->email_canonical)->toBe('petro@gmail.com')
        ->and($other->fresh()->email_canonical)->toBeNull();
});

it('refuses a throwaway address at sign-up, on the address field', function (string $email) {
    $this->post('/register', screenedSignUp(['email' => $email]))
        ->assertSessionHasErrors(['email' => __('validation.email', ['attribute' => __('validation.attributes.email')])]);

    $this->assertGuest();
    expect(User::query()->where('email', $email)->exists())->toBeFalse();
})->with([
    'a listed domain' => 'someone@mailinator.com',
    'a subdomain of one' => 'someone@inbox.mailinator.com',
]);

it('lets an ordinary address through', function (string $email) {
    $this->post('/register', screenedSignUp(['email' => $email]))->assertSessionHasNoErrors();

    $this->assertAuthenticated();
})->with(['someone@outlook.com', 'someone@gmail.com', 'someone@example.com']);

it('refuses another spelling of a Gmail mailbox, as a taken address', function (string $spelling) {
    User::factory()->create(['email' => 'ivan@gmail.com']);

    $this->post('/register', screenedSignUp(['email' => $spelling]))
        ->assertSessionHasErrors(['email' => __('validation.unique', ['attribute' => __('validation.attributes.email')])]);

    $this->assertGuest();
})->with(['i.van@gmail.com', 'ivan+new@gmail.com', 'ivan@googlemail.com']);

it('does not say which check failed when a script also tripped another', function () {
    $this->post('/register', screenedSignUp([
        'email' => 'someone@mailinator.com',
        ScreenRegistrationAction::HONEYPOT_FIELD => 'x',
    ]))
        ->assertSessionHasErrors([ScreenRegistrationAction::ERROR_FIELD => __('auth.register.refused')])
        ->assertSessionDoesntHaveErrors('email');
});

it('only logs an address in report mode, and the sign-up goes through', function () {
    config(['registration.screening_mode' => 'report']);
    $log = fakeSecurityLog();
    User::factory()->create(['email' => 'ivan@gmail.com']);

    $this->post('/register', screenedSignUp(['email' => 'i.van@gmail.com']))->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    $line = collect($log->getRecords())->first(fn (LogRecord $record): bool => $record->message === ScreenRegistrationAction::EVENT);
    expect($line->context)->toMatchArray(['reasons' => ['gmail_alias'], 'refused' => false])
        ->and(json_encode($line->context))->not->toContain('gmail.com');
});

it('does not spend the limit on a refused address', function () {
    $this->post('/register', screenedSignUp(['email' => 'someone@mailinator.com']));

    expect(RateLimiter::attempts(RateLimitKey::registration('hour', '127.0.0.1')))->toBe(0);
});

it('does not screen a sign-up when it is off', function () {
    config(['registration.screening_mode' => 'off']);

    $this->post('/register', [
        'name' => 'New Member',
        'email' => 'someone@mailinator.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();
});

it('refuses a throwaway address or an alias on the profile', function (string $email, string $key) {
    User::factory()->create(['email' => 'ivan@gmail.com']);
    $user = User::factory()->create(['username' => 'kept_user', 'email' => 'kept@example.com']);

    $this->actingAs($user)->patch('/profile', [
        'name' => 'Kept',
        'username' => 'kept_user',
        'email' => $email,
        'current_password' => 'password',
    ])->assertSessionHasErrors(['email' => __($key, ['attribute' => __('validation.attributes.email')])]);

    expect($user->fresh()->email)->toBe('kept@example.com');
})->with([
    'throwaway' => ['someone@mailinator.com', 'validation.email'],
    'alias' => ['i.van@gmail.com', 'validation.unique'],
]);

it('lets someone change the spelling of their own Gmail address', function () {
    $user = User::factory()->create(['email' => 'ivan@gmail.com']);

    expect(fn () => app(ScreenEmailChangeAction::class)->execute($user, 'i.van@gmail.com'))
        ->not->toThrow(ValidationException::class);
});

it('leaves an address the account already has alone', function () {
    $user = User::factory()->create(['email' => 'someone@mailinator.com']);

    expect(fn () => app(ScreenEmailChangeAction::class)->execute($user, 'someone@mailinator.com'))
        ->not->toThrow(ValidationException::class);
});

it('only logs a new address in report mode', function () {
    config(['registration.screening_mode' => 'report']);
    $log = fakeSecurityLog();
    $user = User::factory()->create();

    app(ScreenEmailChangeAction::class)->execute($user, 'someone@mailinator.com');

    $line = collect($log->getRecords())->first(fn (LogRecord $record): bool => $record->message === ScreenEmailChangeAction::EVENT);
    expect($line->context)->toMatchArray(['user_id' => $user->id, 'reasons' => ['disposable_email'], 'refused' => false])
        ->and(json_encode($line->context))->not->toContain('mailinator');
});

it('throws for a refused new address', function () {
    expect(fn () => app(ScreenEmailChangeAction::class)->execute(User::factory()->create(), 'someone@mailinator.com'))
        ->toThrow(ValidationException::class);
});
