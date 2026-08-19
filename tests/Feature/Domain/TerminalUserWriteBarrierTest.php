<?php

use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\UpdatePasswordAction;
use App\Actions\Auth\VerifyEmailAction;
use App\Actions\Locale\ChangeLocaleAction;
use App\Actions\Posts\SavePostAction;
use App\Actions\Posts\TogglePostSaveAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Users\UpdateNotificationPreferencesAction;
use App\Actions\Users\UpdateThemePreferenceAction;
use App\Enums\ThemePreference;
use App\Exceptions\SavedPosts\CannotSavePostException;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/*
 * Terminal-state invariant: UserStatus::Deleted is an irreversible
 * anonymized tombstone. After anonymization commits, no stale request may
 * write mutable User state back into that row. Living sanctions
 * (Limited/Banned/Shadowbanned) keep their private/security operations.
 */

function staleThenAnonymized(): User
{
    $user = User::factory()->create();

    // Anonymization commits behind the stale instance's back.
    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($user->id));

    expect($user->isTombstoned())->toBeFalse();

    return $user;
}

it('rejects a stale password update against a tombstone without mutating it', function () {
    $stale = staleThenAnonymized();
    $tombstone = $stale->fresh();
    $passwordBefore = $tombstone->password;
    $anonymizedAt = $tombstone->anonymized_at;

    expect(fn () => app(UpdatePasswordAction::class)->execute($stale, 'new-secret-password'))
        ->toThrow(ValidationException::class);

    $after = $stale->fresh();
    expect($after->password)->toBe($passwordBefore)
        ->and($after->status)->toBe(App\Enums\UserStatus::Deleted)
        ->and($after->anonymized_at?->equalTo($anonymizedAt))->toBeTrue();
});

it('lets every living sanctioned account still change its password', function (string $state) {
    $user = User::factory()->{$state}()->create();
    $before = $user->password;

    app(UpdatePasswordAction::class)->execute($user, 'new-secret-password');

    expect($user->fresh()->password)->not->toBe($before);
})->with(['limited', 'banned', 'shadowbanned']);

it('rejects a stale password reset for a tombstone generically, without event or mutation', function () {
    Event::fake([PasswordReset::class]);

    $user = User::factory()->create();
    $token = Password::createToken($user);

    // Anonymization wins between token issuance and the reset submit. The
    // broker still holds the pre-anonymization email, so craft the stale
    // window by restoring lookup: anonymize AFTER the broker resolves is
    // impractical here, so simulate the terminal write barrier directly —
    // the row is Deleted by the time the callback locks it.
    $email = $user->email;
    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($user->id));

    // The tombstone's email is scrambled, so the broker's own lookup now
    // yields the generic invalid-user outcome — no mutation either way.
    $passwordBefore = $user->fresh()->password;

    try {
        app(ResetPasswordAction::class)->execute([
            'token' => $token,
            'email' => $email,
            'password' => 'brand-new-password',
        ]);
        $this->fail('Expected ValidationException.');
    } catch (ValidationException $e) {
        // Generic outcome only: nothing reveals the account was deleted.
        expect(implode(' ', $e->errors()['email'] ?? []))->not->toContain('deleted');
    }

    expect($user->fresh()->password)->toBe($passwordBefore);
    Event::assertNotDispatched(PasswordReset::class);
});

it('performs an ordinary password reset for a living account', function () {
    Event::fake([PasswordReset::class]);

    $user = User::factory()->create();
    $token = Password::createToken($user);
    $before = $user->password;

    app(ResetPasswordAction::class)->execute([
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password',
    ]);

    expect($user->fresh()->password)->not->toBe($before);
    Event::assertDispatched(PasswordReset::class);
});

it('never re-verifies email on a tombstone through a stale request', function () {
    $user = User::factory()->unverified()->create();
    $stale = User::findOrFail($user->id);

    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($user->id));

    expect(app(VerifyEmailAction::class)->execute($stale))->toBeFalse();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('still verifies email for living accounts and reports already-verified', function () {
    $user = User::factory()->unverified()->create();

    expect(app(VerifyEmailAction::class)->execute($user))->toBeTrue()
        ->and($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(app(VerifyEmailAction::class)->execute($user->fresh()))->toBeFalse();
});

it('keeps session locale but never persists preferences to a tombstone', function () {
    $stale = staleThenAnonymized();
    $localeBefore = $stale->fresh()->locale;

    $request = Illuminate\Http\Request::create('/locale', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $stale);

    app(ChangeLocaleAction::class)->execute('ru', $request);

    expect($request->session()->get('locale'))->toBe('ru')
        ->and($stale->fresh()->locale)->toBe($localeBefore);
});

it('never persists theme or notification preferences to a tombstone', function () {
    $stale = staleThenAnonymized();
    $fresh = $stale->fresh();
    $themeBefore = $fresh->theme_preference;
    $notifyBefore = (bool) $fresh->notify_followed_author_posts;

    app(UpdateThemePreferenceAction::class)->handle($stale, ThemePreference::Dark);
    app(UpdateNotificationPreferencesAction::class)->handle($stale, ! $notifyBefore);

    $after = $stale->fresh();
    expect($after->theme_preference)->toBe($themeBefore)
        ->and((bool) $after->notify_followed_author_posts)->toBe($notifyBefore);
});

it('still persists preferences for living sanctioned accounts', function (string $state) {
    $user = User::factory()->{$state}()->create(['locale' => 'en']);

    app(UpdateThemePreferenceAction::class)->handle($user, ThemePreference::Dark);

    expect($user->fresh()->theme_preference)->toBe(ThemePreference::Dark->value);
})->with(['limited', 'banned', 'shadowbanned']);

// ------------------------------------------------------- saved-post races

it('cannot recreate a PostSave through a stale request after anonymization', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $stale = staleThenAnonymized();
    $post = Post::factory()->published()->create();

    expect(fn () => app(SavePostAction::class)->handle($stale, $post))
        ->toThrow(CannotSavePostException::class);

    expect(fn () => app(TogglePostSaveAction::class)->handle($stale, $post))
        ->toThrow(CannotSavePostException::class);

    expect(PostSave::query()->where('user_id', $stale->id)->count())->toBe(0);
});

it('removes saves via PR-B cleanup when the save committed first', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(SavePostAction::class)->handle($user, $post);
    expect(PostSave::query()->where('user_id', $user->id)->count())->toBe(1);

    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($user->id));

    // Final tombstone state: zero PostSave rows.
    expect(PostSave::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('keeps saved posts usable for living sanctioned accounts', function (string $state) {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->{$state}()->create();
    $post = Post::factory()->published()->create();

    app(SavePostAction::class)->handle($user, $post);
    expect(PostSave::query()->where('user_id', $user->id)->count())->toBe(1);
})->with(['limited', 'banned', 'shadowbanned']);
