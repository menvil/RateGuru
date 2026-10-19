<?php

use App\Actions\Personas\UpdatePersonaAction;
use App\Data\Personas\PersonaData;
use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Models\Comment;
use App\Models\PersonaProfile;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * Editing a Persona: its public profile and its Content Studio profile,
 * together or not at all, by an active administrator, on the rows as they are
 * now — and never over somebody else's save.
 */
function personaWithProfile(array $account = [], array $profile = []): User
{
    return PersonaProfile::factory()
        ->for(User::factory()->persona()->state($account), 'user')
        ->create($profile)
        ->user;
}

/** The Persona as stored now, as an editor drawn from it would hold it. */
function storedPersona(User $persona): PersonaData
{
    $persona = $persona->fresh(['personaProfile']);

    return PersonaData::of($persona, $persona->personaProfile);
}

function updatePersona(User $admin, User $persona, array $changes = [], ?string $fingerprint = null): void
{
    $stored = storedPersona($persona);

    app(UpdatePersonaAction::class)->handle(
        $admin,
        $persona,
        new PersonaData(...[...get_object_vars($stored), ...$changes]),
        $fingerprint ?? $stored->fingerprint(),
    );
}

it('saves the public profile and the Content Studio profile together', function () {
    $admin = User::factory()->admin()->create();
    $persona = personaWithProfile(['username' => 'old_name']);

    updatePersona($admin, $persona, [
        'displayName' => 'Night Owl',
        'username' => 'night_owl',
        'bio' => 'Up late.',
        'shortDescription' => 'Insomniac with opinions',
        'character' => 'Dry, patient.',
        'speechStyle' => 'Long sentences.',
        'topics' => 'Cats at night',
        'neverWritesAbout' => 'Medicine',
        'emojiFrequency' => PersonaEmojiFrequency::Frequent,
        'engagement' => PersonaEngagement::High,
        'maxRepliesPerDay' => 12,
    ]);

    $persona = $persona->fresh(['personaProfile']);

    expect($persona)
        ->name->toBe('Night Owl')
        ->display_name->toBe('Night Owl')
        ->username->toBe('night_owl')
        ->bio->toBe('Up late.')
        ->and($persona->personaProfile)
        ->short_description->toBe('Insomniac with opinions')
        ->character->toBe('Dry, patient.')
        ->speech_style->toBe('Long sentences.')
        ->topics->toBe('Cats at night')
        ->never_writes_about->toBe('Medicine')
        ->emoji_frequency->toBe(PersonaEmojiFrequency::Frequent)
        ->engagement->toBe(PersonaEngagement::High)
        ->max_replies_per_day->toBe(12);
});

it('changes nothing the editor has no room for: role, status, trust, address, password, tokens, identities', function () {
    $admin = User::factory()->admin()->create();
    $persona = personaWithProfile(['trust_level' => 3]);
    $creator = $persona->personaProfile->created_by_user_id;
    $before = $persona->fresh()->only(['role', 'status', 'trust_level', 'email', 'email_verified_at', 'password', 'remember_token', 'session_generation', 'anonymized_at', 'avatar_asset_id']);

    updatePersona($admin, $persona, ['displayName' => 'Renamed', 'engagement' => PersonaEngagement::Normal]);

    expect($persona->fresh()->only(array_keys($before)))->toBe($before)
        ->and($persona->fresh()->personaProfile->created_by_user_id)->toBe($creator)
        ->and(SocialAccount::query()->where('user_id', $persona->id)->exists())->toBeFalse();
});

it('keeps the username when only the display name changes', function () {
    $persona = personaWithProfile(['username' => 'max_power', 'name' => 'Max Power', 'display_name' => 'Max Power']);

    updatePersona(User::factory()->admin()->create(), $persona, ['displayName' => 'Maximilian']);

    expect($persona->fresh())->username->toBe('max_power')->display_name->toBe('Maximilian');
});

it('corrects a sanctioned persona\'s settings and leaves it as sanctioned as it was', function (UserStatus $status) {
    $persona = personaWithProfile(['status' => $status]);

    updatePersona(User::factory()->admin()->create(), $persona, ['character' => 'Calmer now.']);

    expect($persona->fresh())->status->toBe($status)
        ->and($persona->fresh()->personaProfile->character)->toBe('Calmer now.')
        ->and($persona->fresh()->canCreateContent())->toBeFalse();
})->with([
    'limited' => UserStatus::Limited,
    'banned' => UserStatus::Banned,
    'shadowbanned' => UserStatus::Shadowbanned,
]);

it('edits no account that is not a persona through this action', function (Closure $target) {
    $target = $target();
    $before = $target->fresh()->getAttributes();

    expect(fn () => app(UpdatePersonaAction::class)->handle(
        User::factory()->admin()->create(),
        $target,
        new PersonaData(displayName: 'Taken Over', username: (string) $target->username),
        PersonaData::of($target, null)->fingerprint(),
    ))->toThrow(fn (CannotManagePersonaException $exception) => expect($exception->isGone())->toBeTrue());

    expect($target->fresh()->getAttributes())->toBe($before)
        ->and(PersonaProfile::query()->where('user_id', $target->id)->exists())->toBeFalse();
})->with([
    'a member' => fn () => User::factory()->create(),
    'a moderator' => fn () => User::factory()->moderator()->create(),
    'an administrator' => fn () => User::factory()->admin()->create(),
]);

it('never edits a deleted persona', function () {
    $persona = User::factory()->persona()->tombstoned()->create();
    $before = $persona->fresh()->getAttributes();

    expect(fn () => updatePersona(User::factory()->admin()->create(), $persona, ['displayName' => 'Back again']))
        ->toThrow(fn (CannotManagePersonaException $exception) => expect($exception->isGone())->toBeTrue());

    expect($persona->fresh()->getAttributes())->toBe($before);
});

it('reads the role and status from the locked row, not from a stale copy', function (array $now) {
    $persona = personaWithProfile();
    $stale = User::query()->findOrFail($persona->id);
    $fingerprint = storedPersona($persona)->fingerprint();

    User::query()->whereKey($persona->id)->update($now);

    expect(fn () => app(UpdatePersonaAction::class)->handle(
        User::factory()->admin()->create(),
        $stale,
        new PersonaData(displayName: 'Stale write', username: (string) $persona->username),
        $fingerprint,
    ))->toThrow(fn (CannotManagePersonaException $exception) => expect($exception->isGone())->toBeTrue());

    expect($persona->fresh()->display_name)->not->toBe('Stale write');
})->with([
    'made a member' => [['role' => UserRole::User]],
    'deleted' => [['status' => UserStatus::Deleted]],
]);

it('refuses a draft made before somebody else\'s save, keeping what they saved', function () {
    $persona = personaWithProfile(['name' => 'First', 'display_name' => 'First'], ['character' => 'Original']);
    $drawn = storedPersona($persona)->fingerprint();

    // Another administrator saves first.
    updatePersona(User::factory()->admin()->create(), $persona, ['character' => 'Theirs']);

    expect(fn () => updatePersona(User::factory()->admin()->create(), $persona, ['displayName' => 'Mine', 'character' => 'Mine'], $drawn))
        ->toThrow(fn (CannotManagePersonaException $exception) => expect($exception->isStale())->toBeTrue());

    expect($persona->fresh()->display_name)->toBe('First')
        ->and($persona->fresh()->personaProfile->character)->toBe('Theirs');
});

it('writes neither row when the second one fails', function () {
    $persona = personaWithProfile(['name' => 'Kept', 'display_name' => 'Kept'], ['character' => 'Kept']);

    PersonaProfile::updating(function (): void {
        throw new RuntimeException('Simulated profile failure.');
    });

    try {
        expect(fn () => updatePersona(User::factory()->admin()->create(), $persona, ['displayName' => 'Lost', 'character' => 'Lost']))
            ->toThrow(RuntimeException::class, 'Simulated profile failure.');
    } finally {
        PersonaProfile::flushEventListeners();
    }

    expect($persona->fresh()->display_name)->toBe('Kept')
        ->and($persona->fresh()->personaProfile->character)->toBe('Kept');
});

it('refuses a username another account has, and one taken by a concurrent write', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['username' => 'members_name']);
    $persona = personaWithProfile(['username' => 'persona_name']);

    expect(fn () => updatePersona($admin, $persona, ['username' => 'members_name']))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('username'));

    User::updating(function (User $user): void {
        if ($user->role === UserRole::Persona) {
            User::withoutEvents(fn () => User::factory()->create(['username' => 'racing_name']));
        }
    });

    try {
        expect(fn () => updatePersona($admin, $persona, ['username' => 'racing_name']))
            ->toThrow(fn (CannotManagePersonaException $exception) => expect($exception->isUsernameTaken())->toBeTrue());
    } finally {
        User::flushEventListeners();
    }

    expect($persona->fresh()->username)->toBe('persona_name');
});

it('gives a persona made before profiles existed its profile on its first save', function () {
    $persona = User::factory()->persona()->create();

    updatePersona(User::factory()->admin()->create(), $persona, ['shortDescription' => 'Finally described']);

    expect($persona->fresh()->personaProfile)
        ->short_description->toBe('Finally described')
        ->engagement->toBe(PersonaEngagement::Off)
        ->created_by_user_id->toBeNull();
});

it('lets nobody but an active administrator edit', function (Closure $actor) {
    $persona = personaWithProfile(['name' => 'Kept', 'display_name' => 'Kept']);

    expect(fn () => updatePersona($actor(), $persona, ['displayName' => 'Taken']))
        ->toThrow(CannotManagePersonaException::class, 'User is not allowed to manage personas.');

    expect($persona->fresh()->display_name)->toBe('Kept');
})->with([
    'a moderator' => fn () => User::factory()->moderator()->create(),
    'a member' => fn () => User::factory()->create(),
    'another persona' => fn () => User::factory()->persona()->create(),
    'a banned administrator' => fn () => User::factory()->admin()->banned()->create(),
]);

it('asks again on the administrator\'s locked row, so a sanction meanwhile writes nothing', function () {
    $admin = User::factory()->admin()->create();
    $stale = User::query()->findOrFail($admin->id);
    $persona = personaWithProfile(['name' => 'Kept', 'display_name' => 'Kept']);

    User::query()->whereKey($admin->id)->update(['status' => UserStatus::Limited]);

    expect(fn () => updatePersona($stale, $persona, ['displayName' => 'Taken']))
        ->toThrow(CannotManagePersonaException::class);

    expect($persona->fresh()->display_name)->toBe('Kept');
});

it('starts nothing when replies are switched on: no job, no comment', function () {
    Queue::fake();
    Bus::fake();
    $persona = personaWithProfile();

    updatePersona(User::factory()->admin()->create(), $persona, ['engagement' => PersonaEngagement::High, 'maxRepliesPerDay' => 50]);

    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    expect(Comment::query()->where('user_id', $persona->id)->exists())->toBeFalse();
});

it('does not take a password set straight into the row for a sign-in, or touch it', function () {
    $persona = personaWithProfile();
    User::query()->whereKey($persona->id)->update(['password' => Hash::make('secret')]);
    $hash = $persona->fresh()->password;

    updatePersona(User::factory()->admin()->create(), $persona, ['displayName' => 'Still locked']);

    expect($persona->fresh()->password)->toBe($hash)
        ->and($persona->fresh()->canSignIn())->toBeFalse();
});
