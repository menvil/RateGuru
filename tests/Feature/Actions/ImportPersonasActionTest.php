<?php

use App\Actions\Personas\CreatePersonaAction;
use App\Actions\Personas\ImportPersonasAction;
use App\Data\Personas\PersonaData;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * What an import does with the accounts as they are when it runs, not only as
 * they were when it was planned: each Persona made by the persona action
 * itself, a username taken meanwhile told apart — by the same Persona, or by
 * somebody else — an administrator who may no longer manage Personas stopping
 * it where it is, and every outcome counted in its report and its log line.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/** A Persona as a file to import describes it. */
function importedPersona(string $username, array $overrides = []): PersonaData
{
    return new PersonaData(...[
        'displayName' => ucwords(str_replace('_', ' ', $username)),
        'username' => $username,
        'shortDescription' => "The {$username} one.",
        'character' => "Wry, and particular about {$username}.",
        ...$overrides,
    ]);
}

/** What the import logged when it finished. */
function importFinishedLog(): array
{
    $logged = null;

    Log::shouldHaveReceived('info')->withArgs(function (string $event, array $context) use (&$logged): bool {
        if ($event === 'persona.import.finished') {
            $logged = $context;
        }

        return true;
    });

    return array_intersect_key((array) $logged, array_flip(['created', 'unchanged', 'conflicts', 'failed', 'not_attempted']));
}

it('says what each persona is to the accounts there are: new, here already, or in conflict', function () {
    app(CreatePersonaAction::class)->handle($this->admin, importedPersona('already_here'));
    app(CreatePersonaAction::class)->handle($this->admin, importedPersona('edited_since'));
    User::query()->where('username', 'edited_since')->firstOrFail()->forceFill(['bio' => 'Edited by an editor.'])->save();
    User::factory()->create(['username' => 'a_member']);
    User::factory()->persona()->create(['username' => 'long_gone', 'status' => UserStatus::Deleted]);

    $plan = app(ImportPersonasAction::class)->plan([
        importedPersona('brand_new'),
        importedPersona('already_here'),
        importedPersona('edited_since'),
        importedPersona('a_member'),
        importedPersona('long_gone'),
    ]);

    expect(array_map(fn (PersonaData $persona): ?string => $persona->username, $plan->new))->toBe(['brand_new'])
        ->and(array_map(fn (PersonaData $persona): ?string => $persona->username, $plan->unchanged))->toBe(['already_here'])
        ->and($plan->conflicts)->toBe([
            'edited_since' => 'A persona with this username has other details: edited since it was imported, or another persona. Nothing is overwritten.',
            'a_member' => 'An account that is not a persona has this username.',
            'long_gone' => 'A deleted persona still has this username.',
        ])
        ->and($plan->count())->toBe(5);
});

it('stops where it is once the administrator may no longer manage personas, the rest not attempted', function () {
    Log::spy();
    $plan = app(ImportPersonasAction::class)->plan([importedPersona('first_one'), importedPersona('second_one'), importedPersona('third_one')]);

    // Sanctioned after the plan: the row the action locks says so, whatever the copy in hand does.
    User::query()->whereKey($this->admin->id)->update(['status' => UserStatus::Banned->value]);

    $report = app(ImportPersonasAction::class)->handle($this->admin, $plan);

    expect($report->created)->toBe([])
        ->and($report->failed)->toBe(['first_one' => 'The administrator may no longer manage personas, so the import stopped here.'])
        ->and($report->notAttempted)->toBe(['second_one', 'third_one'])
        ->and($report->finished())->toBeFalse()
        ->and(User::query()->where('role', UserRole::Persona)->count())->toBe(0)
        ->and(importFinishedLog())->toBe(['created' => 0, 'unchanged' => 0, 'conflicts' => 0, 'failed' => 1, 'not_attempted' => 2]);
});

it('counts a persona another run made meanwhile as here already, and fails one whose username somebody else took', function () {
    Log::spy();
    $plan = app(ImportPersonasAction::class)->plan([importedPersona('made_meanwhile'), importedPersona('taken_meanwhile'), importedPersona('still_free')]);

    app(CreatePersonaAction::class)->handle($this->admin, importedPersona('made_meanwhile'));
    $member = User::factory()->create(['username' => 'taken_meanwhile']);
    $memberBefore = $member->fresh()->getAttributes();

    $report = app(ImportPersonasAction::class)->handle($this->admin, $plan);

    expect($report->created)->toBe(['still_free'])
        ->and($report->unchanged)->toBe(['made_meanwhile'])
        ->and($report->failed)->toBe(['taken_meanwhile' => 'Another account took this username during the import.'])
        ->and($report->notAttempted)->toBe([])
        ->and($report->finished())->toBeFalse()
        ->and(User::query()->where('username', 'made_meanwhile')->count())->toBe(1)
        ->and($member->fresh()->getAttributes())->toBe($memberBefore)
        ->and(importFinishedLog())->toBe(['created' => 1, 'unchanged' => 1, 'conflicts' => 0, 'failed' => 1, 'not_attempted' => 0]);
});

it('fails a persona the rules refuse, saying why — its username too — and goes on with the others', function () {
    Log::spy();
    $plan = app(ImportPersonasAction::class)->plan([
        importedPersona('too_long_a_bio', ['bio' => str_repeat('a', 501)]),
        importedPersona('Not A Username!'),
        importedPersona('perfectly_fine'),
    ]);

    $report = app(ImportPersonasAction::class)->handle($this->admin, $plan);

    expect($report->created)->toBe(['perfectly_fine'])
        ->and($report->failed)->toBe([
            'too_long_a_bio' => 'Keep the bio to 500 characters or fewer.',
            'Not A Username!' => 'Use lowercase letters, numbers and underscores.',
        ])
        ->and($report->finished())->toBeFalse()
        ->and(User::query()->whereIn('username', ['too_long_a_bio', 'Not A Username!'])->exists())->toBeFalse()
        ->and(importFinishedLog())->toBe(['created' => 1, 'unchanged' => 0, 'conflicts' => 0, 'failed' => 2, 'not_attempted' => 0]);
});

it('leaves the plan’s conflicts out of what it creates, and reports them', function () {
    User::factory()->create(['username' => 'a_member']);
    $plan = app(ImportPersonasAction::class)->plan([importedPersona('a_member'), importedPersona('newcomer')]);

    $report = app(ImportPersonasAction::class)->handle($this->admin, $plan);

    expect($report->created)->toBe(['newcomer'])
        ->and($report->conflicts)->toBe(['a_member' => 'An account that is not a persona has this username.'])
        ->and($report->finished())->toBeTrue()
        ->and(User::query()->where('username', 'a_member')->value('role'))->toBe(UserRole::User);
});
