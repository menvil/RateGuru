<?php

use App\Actions\Personas\CreatePersonaAction;
use App\Data\Personas\PersonaData;
use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\PersonaProfile;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Personas\PersonaFileReader;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * `studio:personas:import`: a project's Personas from a JSON file, each made
 * by CreatePersonaAction as the administrator named, the whole file checked
 * before anything is written, nothing that exists ever overwritten — and a
 * second run, or one after a run cut short, creating only what is missing.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->scratch = makeScratchDir('persona-import');
});

afterEach(function () {
    removeScratchDir($this->scratch);
});

/** One entry of a Persona file, every field given. */
function personaFileEntry(array $overrides = []): array
{
    return [
        'displayName' => 'Velvet Footnote',
        'username' => 'velvet_footnote',
        'bio' => 'Reads the small print. Flirts in it too.',
        'shortDescription' => 'A shy flirt who hides compliments in footnotes.',
        'character' => 'Warm, bookish and a little evasive. She notices everything and admits to half of it.',
        'speechStyle' => 'One line, lowercase, ends with a footnote marker.',
        'topics' => 'Small print, accidental innuendo, library humour',
        'neverWritesAbout' => 'Anything graphic, guessing anyone’s age',
        'emojiFrequency' => 'none',
        'engagement' => 'off',
        'maxRepliesPerDay' => 0,
        ...$overrides,
    ];
}

/**
 * Writes a Persona file to the test's scratch directory.
 *
 * @param  list<array<string, mixed>>|string  $entries  the entries, or the file's raw text
 */
function personaFile(string $scratch, array|string $entries, string $name = 'personas.json'): string
{
    $path = "{$scratch}/{$name}";
    file_put_contents($path, is_string($entries) ? $entries : json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $path;
}

/** Two Personas, with usernames of their own. */
function twoPersonaEntries(): array
{
    return [
        personaFileEntry(),
        personaFileEntry([
            'displayName' => 'Mx. Lowkey',
            'username' => 'mx_lowkey',
            'bio' => null,
            'shortDescription' => 'Deadpan, two words at a time.',
            'emojiFrequency' => 'occasional',
        ]),
    ];
}

it('creates every persona the project’s file holds, each through the persona action, closed and with its profile as the file has it', function () {
    $path = 'resources/studio/personas/tits-guru.json';
    $entries = json_decode((string) file_get_contents(base_path($path)), true);

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsOutputToContain('100 personas, every one valid.')
        ->expectsOutputToContain('Import finished.')
        ->assertSuccessful();

    $personas = User::query()->where('role', UserRole::Persona)->with('personaProfile')->get()->keyBy('username');

    expect($personas)->toHaveCount(100)
        ->and(PersonaProfile::query()->count())->toBe(100);

    foreach ($entries as $entry) {
        $persona = $personas->get($entry['username']);

        expect($persona)->not->toBeNull()
            ->and(PersonaData::of($persona, $persona->personaProfile)->toArray())->toBe((new PersonaData(
                displayName: $entry['displayName'],
                username: $entry['username'],
                bio: $entry['bio'],
                shortDescription: $entry['shortDescription'],
                character: $entry['character'],
                speechStyle: $entry['speechStyle'],
                topics: $entry['topics'],
                neverWritesAbout: $entry['neverWritesAbout'],
                emojiFrequency: PersonaEmojiFrequency::from($entry['emojiFrequency']),
                engagement: PersonaEngagement::from($entry['engagement']),
                maxRepliesPerDay: $entry['maxRepliesPerDay'],
            ))->normalized()->toArray())
            ->and($persona)
            ->status->toBe(UserStatus::Active)
            ->password->toBeNull()
            ->remember_token->toBeNull()
            ->email_verified_at->toBeNull()
            ->and($persona->email)->toEndWith('@'.CreatePersonaAction::EMAIL_DOMAIN)
            ->and($persona->personaProfile->created_by_user_id)->toBe($this->admin->id)
            ->and($persona->personaProfile->engagement)->toBe(PersonaEngagement::Off)
            ->and($persona->personaProfile->max_replies_per_day)->toBe(0);
    }

    // Nothing to sign in with: no password, no sign-in identity.
    $persona = $personas->first();

    expect(SocialAccount::query()->whereIn('user_id', $personas->pluck('id'))->count())->toBe(0)
        ->and(Auth::validate(['email' => $persona->email, 'password' => '']))->toBeFalse()
        ->and(Auth::validate(['email' => $persona->email, 'password' => 'password']))->toBeFalse();
});

it('says what an import would do on a dry run, and writes nothing', function () {
    $path = personaFile($this->scratch, twoPersonaEntries());
    $users = User::query()->count();

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id, '--dry-run' => true])
        ->expectsOutputToContain('2 personas, every one valid.')
        ->expectsTable(['To create', 'Here already', 'Conflicts'], [[2, 0, 0]])
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->assertSuccessful();

    expect(User::query()->count())->toBe($users)
        ->and(PersonaProfile::query()->count())->toBe(0);
});

it('creates nothing twice: a second run finds every persona here already', function () {
    $path = personaFile($this->scratch, twoPersonaEntries());

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])->assertSuccessful();
    $before = User::query()->where('role', UserRole::Persona)->orderBy('id')->get()->map(fn (User $persona): array => $persona->getAttributes())->all();

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsTable(['To create', 'Here already', 'Conflicts'], [[0, 2, 0]])
        ->expectsOutputToContain('Nothing to import: every persona in the file is here already.')
        ->assertSuccessful();

    expect(User::query()->where('role', UserRole::Persona)->orderBy('id')->get()->map(fn (User $persona): array => $persona->getAttributes())->all())->toBe($before)
        ->and(PersonaProfile::query()->count())->toBe(2);
});

it('finishes an import cut short: the personas made already are kept, the rest are created', function () {
    $entries = [
        ...twoPersonaEntries(),
        personaFileEntry(['displayName' => 'Third Wheel', 'username' => 'third_wheel', 'shortDescription' => 'Always arrives late to the joke.']),
    ];
    $path = personaFile($this->scratch, $entries);
    $first = app(PersonaFileReader::class)->read($path)[0];
    $made = app(CreatePersonaAction::class)->handle($this->admin, $first);

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsTable(['To create', 'Here already', 'Conflicts'], [[2, 1, 0]])
        ->expectsTable(['Created', 'Skipped: here already', 'Skipped: conflicts', 'Failed', 'Not attempted'], [[2, 1, 0, 0, 0]])
        ->assertSuccessful();

    expect(User::query()->where('role', UserRole::Persona)->pluck('username')->sort()->values()->all())->toBe(['mx_lowkey', 'third_wheel', 'velvet_footnote'])
        ->and(User::query()->where('username', 'velvet_footnote')->value('id'))->toBe($made->id);
});

it('refuses a file that names a username twice, creating nothing', function () {
    $path = personaFile($this->scratch, [personaFileEntry(), personaFileEntry(['displayName' => 'Velvet Again'])]);

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsOutputToContain('Nothing was imported: The Persona file has 1 problem.')
        ->expectsOutputToContain('Entry 2 (velvet_footnote): the username is entry 1’s too.')
        ->assertFailed();

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(0);
});

it('leaves a member who has a username alone, and imports nothing unless asked to import the others', function () {
    $member = User::factory()->create(['username' => 'velvet_footnote', 'name' => 'A real member']);
    $memberBefore = $member->fresh()->getAttributes();
    $path = personaFile($this->scratch, twoPersonaEntries());

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsTable(['To create', 'Here already', 'Conflicts'], [[1, 0, 1]])
        ->expectsOutputToContain('velvet_footnote: An account that is not a persona has this username.')
        ->expectsOutputToContain('Nothing was imported: settle the conflicts above, or run again with --skip-conflicts to import the others.')
        ->assertFailed();

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(0);

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id, '--skip-conflicts' => true])
        ->expectsTable(['Created', 'Skipped: here already', 'Skipped: conflicts', 'Failed', 'Not attempted'], [[1, 0, 1, 0, 0]])
        ->assertSuccessful();

    expect(User::query()->where('role', UserRole::Persona)->pluck('username')->all())->toBe(['mx_lowkey'])
        ->and($member->fresh()->getAttributes())->toBe($memberBefore);
});

it('never overwrites a persona edited since it was imported', function () {
    $path = personaFile($this->scratch, twoPersonaEntries());
    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])->assertSuccessful();

    $edited = User::query()->where('username', 'mx_lowkey')->firstOrFail();
    $edited->personaProfile->forceFill(['character' => 'Rewritten by an editor.'])->save();
    $before = $edited->personaProfile->fresh()->getAttributes();

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsTable(['To create', 'Here already', 'Conflicts'], [[0, 1, 1]])
        ->expectsOutputToContain('mx_lowkey: A persona with this username has other details')
        ->assertFailed();

    expect($edited->personaProfile->fresh()->getAttributes())->toBe($before);
});

it('refuses a file that is not JSON', function () {
    $path = personaFile($this->scratch, '[{"displayName": "Broken",');

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsOutputToContain('is not valid JSON')
        ->assertFailed();

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(0);
});

it('refuses a file that is not a list of personas', function (string $contents) {
    $path = personaFile($this->scratch, $contents);

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsOutputToContain('The file must hold a JSON list of Personas, at least one.')
        ->assertFailed();
})->with([
    'an object' => ['{"personas": []}'],
    'an empty list' => ['[]'],
    'a string' => ['"velvet_footnote"'],
]);

it('refuses a file that is not there', function () {
    $this->artisan('studio:personas:import', ['file' => "{$this->scratch}/nowhere.json", '--admin' => $this->admin->id])
        ->expectsOutputToContain('is not a file that can be read.')
        ->assertFailed();
});

it('lists every problem of every entry, and creates nothing — not even the valid ones', function () {
    $missing = personaFileEntry(['username' => 'no_topics']);
    unset($missing['topics'], $missing['engagement']);

    $path = personaFile($this->scratch, [
        personaFileEntry(['username' => 'fine_one']),
        $missing,
        personaFileEntry(['username' => 'extra_field', 'avatar' => 'https://example.test/a.png']),
        personaFileEntry(['username' => 'bad_enums', 'emojiFrequency' => 'always', 'engagement' => 'max']),
        personaFileEntry(['username' => 'too_much', 'bio' => str_repeat('a', 501), 'character' => str_repeat('b', 2001), 'maxRepliesPerDay' => 51]),
        personaFileEntry(['username' => 'Not Valid!']),
        personaFileEntry(['username' => 'wrong_types', 'maxRepliesPerDay' => '0', 'bio' => ['a list']]),
        personaFileEntry(['username' => 'no_name', 'displayName' => '   ']),
        'just a string',
    ]);

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsOutputToContain('Entry 2 (no_topics): missing topics, engagement.')
        ->expectsOutputToContain('Entry 3 (extra_field): no such field as avatar.')
        ->expectsOutputToContain('Entry 4 (bad_enums): emojiFrequency — Choose how often it uses emoji.')
        ->expectsOutputToContain('Entry 4 (bad_enums): engagement — Choose an engagement level.')
        ->expectsOutputToContain('Entry 5 (too_much): bio — Keep the bio to 500 characters or fewer.')
        ->expectsOutputToContain('Entry 5 (too_much): character — Keep it to 2,000 characters or fewer.')
        ->expectsOutputToContain('Entry 5 (too_much): maxRepliesPerDay — Enter a whole number from 0 to 50.')
        ->expectsOutputToContain('Entry 6 (Not Valid!): username — Use lowercase letters, numbers and underscores.')
        ->expectsOutputToContain('Entry 7 (wrong_types): bio must be text, or null.')
        ->expectsOutputToContain('Entry 7 (wrong_types): maxRepliesPerDay must be a whole number.')
        ->expectsOutputToContain('Entry 8 (no_name): displayName — Enter a display name.')
        ->expectsOutputToContain('Entry 9: must be an object with a Persona’s fields.')
        ->assertFailed();

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(0);
});

it('needs an administrator who may manage personas, on a dry run too', function (Closure $admin, string $message) {
    $path = personaFile($this->scratch, twoPersonaEntries());

    $this->artisan('studio:personas:import', ['file' => $path, '--dry-run' => true, ...$admin()])
        ->expectsOutputToContain($message)
        ->assertFailed();

    $this->artisan('studio:personas:import', ['file' => $path, ...$admin()])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(0);
})->with([
    'none named' => [fn () => [], 'Name the administrator who creates the personas: --admin=<user id>.'],
    'not a number' => [fn () => ['--admin' => 'root'], 'Name the administrator who creates the personas'],
    'no such user' => [fn () => ['--admin' => 999_999], 'There is no user #999999.'],
    'a moderator' => [fn () => ['--admin' => User::factory()->moderator()->create()->id], 'may not manage personas'],
    'a member' => [fn () => ['--admin' => User::factory()->create()->id], 'may not manage personas'],
    'a banned administrator' => [fn () => ['--admin' => User::factory()->admin()->create(['status' => UserStatus::Banned])->id], 'may not manage personas'],
]);

it('changes no account that is here already', function () {
    $member = User::factory()->create();
    $persona = User::factory()->persona()->create(['username' => 'an_old_persona']);
    $before = [$member->fresh()->getAttributes(), $persona->fresh()->getAttributes(), $this->admin->fresh()->getAttributes()];

    $this->artisan('studio:personas:import', ['file' => personaFile($this->scratch, twoPersonaEntries()), '--admin' => $this->admin->id])->assertSuccessful();

    expect([$member->fresh()->getAttributes(), $persona->fresh()->getAttributes(), $this->admin->fresh()->getAttributes()])->toBe($before)
        ->and(User::query()->where('role', UserRole::Persona)->count())->toBe(3);
});

it('asks before it writes in production, and writes nothing when told no', function () {
    $this->app['env'] = 'production';
    $path = personaFile($this->scratch, twoPersonaEntries());

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsConfirmation('Create 2 personas in production?', 'no')
        ->expectsOutputToContain('Import cancelled: nothing was written.')
        ->assertFailed();

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(0);

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id])
        ->expectsConfirmation('Create 2 personas in production?', 'yes')
        ->assertSuccessful();

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(2);
});

it('imports in production without a question when forced, and never asks on a dry run', function () {
    $this->app['env'] = 'production';
    $path = personaFile($this->scratch, twoPersonaEntries());

    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id, '--dry-run' => true])->assertSuccessful();
    $this->artisan('studio:personas:import', ['file' => $path, '--admin' => $this->admin->id, '--force' => true])->assertSuccessful();

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(2);
});

it('stops in production with nobody to answer, unless forced', function () {
    $this->app['env'] = 'production';

    $this->artisan('studio:personas:import', ['file' => personaFile($this->scratch, twoPersonaEntries()), '--admin' => $this->admin->id, '--no-interaction' => true])
        ->expectsOutputToContain('Importing in production without a terminal requires --force.')
        ->assertFailed();

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(0);
});

it('logs how the import went, and nothing a persona says', function () {
    Log::spy();

    $this->artisan('studio:personas:import', ['file' => personaFile($this->scratch, twoPersonaEntries()), '--admin' => $this->admin->id])->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(function (string $event, array $context): bool {
        return $event === 'persona.import.finished'
            && $context['admin_id'] === $this->admin->id
            && $context['created'] === 2
            && $context['unchanged'] === 0
            && $context['conflicts'] === 0
            && $context['failed'] === 0
            && ! str_contains(json_encode($context), 'Footnote');
    })->once();
});
