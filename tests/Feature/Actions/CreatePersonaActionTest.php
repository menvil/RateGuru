<?php

use App\Actions\Personas\CreatePersonaAction;
use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Data\Personas\PersonaData;
use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Auth\CannotGenerateUsernameException;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Models\PersonaProfile;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Making a Persona: an ordinary account with the Persona role and its
 * Content Studio profile, created together by an active administrator, with
 * everything that could let anyone in set closed by the server.
 */
function createPersona(User $admin, array $data = []): User
{
    return app(CreatePersonaAction::class)->handle($admin, new PersonaData(...[
        'displayName' => 'Max Power',
        ...$data,
    ]));
}

it('makes an active persona and its profile for an active administrator', function () {
    $admin = User::factory()->admin()->create();

    $persona = createPersona($admin, [
        'username' => 'max_power',
        'bio' => 'Dogs, mostly.',
        'shortDescription' => 'Sarcastic one-liners',
        'character' => 'Grumpy but kind.',
        'speechStyle' => 'Short sentences.',
        'topics' => 'Lazy pets',
        'neverWritesAbout' => 'Politics',
        'emojiFrequency' => PersonaEmojiFrequency::Occasional,
        'engagement' => PersonaEngagement::Low,
        'maxRepliesPerDay' => 7,
    ])->fresh();

    expect($persona)
        ->role->toBe(UserRole::Persona)
        ->status->toBe(UserStatus::Active)
        ->name->toBe('Max Power')
        ->display_name->toBe('Max Power')
        ->username->toBe('max_power')
        ->bio->toBe('Dogs, mostly.')
        ->and($persona->personaProfile)
        ->short_description->toBe('Sarcastic one-liners')
        ->character->toBe('Grumpy but kind.')
        ->speech_style->toBe('Short sentences.')
        ->topics->toBe('Lazy pets')
        ->never_writes_about->toBe('Politics')
        ->emoji_frequency->toBe(PersonaEmojiFrequency::Occasional)
        ->engagement->toBe(PersonaEngagement::Low)
        ->max_replies_per_day->toBe(7)
        ->created_by_user_id->toBe($admin->id);
});

it('closes every way in: no password, token, identity or confirmed address, and a unique address on the reserved domain', function () {
    $admin = User::factory()->admin()->create();

    $first = createPersona($admin)->fresh();
    $second = createPersona($admin)->fresh();

    foreach ([$first, $second] as $persona) {
        expect($persona->email)->toMatch('/\Apersona-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}@persona\.invalid\z/')
            ->and($persona->getAuthPassword())->toBeNull()
            ->and($persona->remember_token)->toBeNull()
            ->and($persona->email_verified_at)->toBeNull()
            ->and($persona->hasVerifiedEmail())->toBeFalse()
            ->and($persona->canSignIn())->toBeFalse()
            ->and(SocialAccount::query()->where('user_id', $persona->id)->exists())->toBeFalse();
    }

    expect($first->email)->not->toBe($second->email)
        ->and($first->email)->not->toContain($admin->email)
        ->and($first->email)->not->toContain((string) $first->username);
});

it('gives a new persona a new account\'s trust and starts it replying to nobody', function () {
    $persona = createPersona(User::factory()->admin()->create())->fresh();

    expect($persona->trust_level)->toBe(10)
        ->and($persona->personaProfile)
        ->engagement->toBe(PersonaEngagement::Off)
        ->max_replies_per_day->toBe(0)
        ->emoji_frequency->toBe(PersonaEmojiFrequency::None);
});

it('announces nothing: no Registered event, no mail and no session', function () {
    Event::fake([Registered::class]);
    Mail::fake();
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $persona = createPersona($admin);

    Event::assertNotDispatched(Registered::class);
    Mail::assertNothingOutgoing();
    Notification::assertNothingSentTo($persona);
    expect(auth()->id())->toBe($admin->id);
});

it('makes the username from the display name when none is given', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['username' => 'max_power']);

    expect(createPersona($admin, ['displayName' => 'Max Power'])->username)->toBe('max_power_2');
});

it('keeps a valid username it is given', function () {
    expect(createPersona(User::factory()->admin()->create(), ['username' => 'night_owl_7'])->username)->toBe('night_owl_7');
});

it('refuses a username another account has, a member\'s included, and renames nobody', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['username' => 'taken_name']);

    expect(fn () => createPersona($admin, ['username' => 'taken_name']))
        ->toThrow(ValidationException::class);

    expect($member->fresh()->username)->toBe('taken_name')
        ->and(User::query()->where('role', UserRole::Persona)->exists())->toBeFalse();
});

it('says a username taken between the check and the write is taken, rather than writing over it', function () {
    $admin = User::factory()->admin()->create();

    // Taken by somebody else after every check, as a concurrent write would:
    // the database's own constraint is what refuses it.
    User::creating(function (User $user): void {
        if ($user->role === UserRole::Persona) {
            User::withoutEvents(fn () => User::factory()->create(['username' => 'racing_name']));
        }
    });

    try {
        expect(fn () => createPersona($admin, ['username' => 'racing_name']))
            ->toThrow(fn (CannotManagePersonaException $exception) => expect($exception->isUsernameTaken())->toBeTrue());
    } finally {
        User::flushEventListeners();
    }

    expect(User::query()->where('role', UserRole::Persona)->exists())->toBeFalse()
        ->and(PersonaProfile::query()->exists())->toBeFalse();
});

it('tries the next username when a concurrent creation takes the one it made', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['username' => 'max_power']);

    // The generator saw max_power free a moment before somebody took it.
    $generator = Mockery::mock(GenerateUniqueUsernameAction::class);
    $generator->shouldReceive('handle')->twice()->andReturn('max_power', 'max_power_2');
    app()->instance(GenerateUniqueUsernameAction::class, $generator);

    $persona = createPersona($admin, ['displayName' => 'Max Power']);

    expect($persona->username)->toBe('max_power_2')
        ->and(User::query()->where('username', 'max_power')->count())->toBe(1)
        ->and(User::query()->where('role', UserRole::Persona)->count())->toBe(1);
});

it('stops when no username can be made, creating nothing', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['username' => 'max_power']);

    $generator = Mockery::mock(GenerateUniqueUsernameAction::class);
    $generator->shouldReceive('handle')->times(CreatePersonaAction::MAX_CREATE_ATTEMPTS)->andReturn('max_power');
    app()->instance(GenerateUniqueUsernameAction::class, $generator);

    expect(fn () => createPersona($admin))->toThrow(CannotGenerateUsernameException::class)
        ->and(User::query()->where('role', UserRole::Persona)->exists())->toBeFalse();
});

it('leaves no persona without its profile when the profile cannot be written', function () {
    $admin = User::factory()->admin()->create();

    PersonaProfile::creating(function (): void {
        throw new RuntimeException('Simulated profile failure.');
    });

    try {
        expect(fn () => createPersona($admin, ['username' => 'half_made']))->toThrow(RuntimeException::class, 'Simulated profile failure.');
    } finally {
        PersonaProfile::flushEventListeners();
    }

    expect(User::query()->where('username', 'half_made')->exists())->toBeFalse()
        ->and(PersonaProfile::query()->exists())->toBeFalse();
});

it('lets nobody but an active administrator make one', function (Closure $actor) {
    $actor = $actor();

    expect(fn () => createPersona($actor))
        ->toThrow(CannotManagePersonaException::class, 'User is not allowed to manage personas.');

    expect(User::query()->where('role', UserRole::Persona)->whereKeyNot($actor->id)->exists())->toBeFalse()
        ->and(PersonaProfile::query()->exists())->toBeFalse();
})->with([
    'a moderator' => fn () => User::factory()->moderator()->create(),
    'a member' => fn () => User::factory()->create(),
    'a persona' => fn () => User::factory()->persona()->create(),
    'a limited administrator' => fn () => User::factory()->admin()->limited()->create(),
    'a banned administrator' => fn () => User::factory()->admin()->banned()->create(),
    'a shadowbanned administrator' => fn () => User::factory()->admin()->shadowbanned()->create(),
]);

it('asks again on the administrator\'s locked row, so a demotion meanwhile makes nothing', function () {
    $admin = User::factory()->admin()->create();
    $stale = User::query()->findOrFail($admin->id);

    User::query()->whereKey($admin->id)->update(['role' => UserRole::Moderator]);

    expect(fn () => createPersona($stale))->toThrow(CannotManagePersonaException::class)
        ->and(User::query()->where('role', UserRole::Persona)->exists())->toBeFalse();
});

it('holds every field to its limits, whatever reaches it', function (array $data, string $field) {
    $admin = User::factory()->admin()->create();

    expect(fn () => createPersona($admin, $data))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));

    expect(User::query()->where('role', UserRole::Persona)->exists())->toBeFalse();
})->with([
    'no display name' => [['displayName' => '   '], 'displayName'],
    'a display name too long' => [['displayName' => str_repeat('a', 81)], 'displayName'],
    'an uppercase username' => [['username' => 'MaxPower'], 'username'],
    'a username with a space' => [['username' => 'max power'], 'username'],
    'a username too long' => [['username' => str_repeat('a', 33)], 'username'],
    'a bio too long' => [['bio' => str_repeat('a', 501)], 'bio'],
    'a short description too long' => [['shortDescription' => str_repeat('a', 501)], 'shortDescription'],
    'a character too long' => [['character' => str_repeat('a', 2001)], 'character'],
    'a speech style too long' => [['speechStyle' => str_repeat('a', 2001)], 'speechStyle'],
    'topics too long' => [['topics' => str_repeat('a', 2001)], 'topics'],
    'limits too long' => [['neverWritesAbout' => str_repeat('a', 2001)], 'neverWritesAbout'],
    'a negative daily limit' => [['maxRepliesPerDay' => -1], 'maxRepliesPerDay'],
    'a daily limit over 50' => [['maxRepliesPerDay' => 51], 'maxRepliesPerDay'],
]);

it('stores the texts as typed at their limits, trimmed, and an empty one as none', function () {
    $persona = createPersona(User::factory()->admin()->create(), [
        'displayName' => '  Max   Power ',
        'bio' => '   ',
        'character' => '  '.str_repeat('c', 2000).'  ',
        'maxRepliesPerDay' => 50,
    ])->fresh();

    expect($persona->display_name)->toBe('Max Power')
        ->and($persona->bio)->toBeNull()
        ->and($persona->personaProfile->character)->toBe(str_repeat('c', 2000))
        ->and($persona->personaProfile->max_replies_per_day)->toBe(50);
});
