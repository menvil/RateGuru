<?php

use App\Enums\PersonaEngagement;
use App\Enums\UserStatus;
use App\Models\PersonaProfile;
use App\Models\User;
use App\Queries\Personas\PersonasQuery;

/**
 * Content Studio's Personas list: only living Personas, the status and the
 * engagement narrowing it, a literal search over the username, the names,
 * the short description and the character, the header's figures, and an
 * order that keeps every Persona in its place across pages.
 */
function personasPage(string $search = '', ?UserStatus $status = null, ?PersonaEngagement $engagement = null, int $page = 1): array
{
    return app(PersonasQuery::class)->paginate($search, $status, $engagement, $page)->pluck('id')->all();
}

function listedPersona(array $account = [], array $profile = []): User
{
    return PersonaProfile::factory()
        ->for(User::factory()->persona()->state($account), 'user')
        ->create($profile)
        ->user;
}

it('lists living personas only, newest first', function () {
    $older = listedPersona(['created_at' => now()->subDays(2)]);
    $newer = listedPersona(['created_at' => now()->subDay()]);
    $banned = listedPersona(['status' => UserStatus::Banned, 'created_at' => now()->subHours(2)]);
    User::factory()->persona()->tombstoned()->create();
    User::factory()->create();
    User::factory()->admin()->create();

    expect(personasPage())->toBe([$banned->id, $newer->id, $older->id]);
});

it('narrows by status and by engagement, a persona without a profile reading as Off', function () {
    $active = listedPersona([], ['engagement' => PersonaEngagement::High]);
    $limited = listedPersona(['status' => UserStatus::Limited], ['engagement' => PersonaEngagement::Low]);
    $shadowbanned = listedPersona(['status' => UserStatus::Shadowbanned], ['engagement' => PersonaEngagement::Off]);
    $unprofiled = User::factory()->persona()->create();

    expect(personasPage(status: UserStatus::Limited))->toBe([$limited->id])
        ->and(personasPage(status: UserStatus::Shadowbanned))->toBe([$shadowbanned->id])
        ->and(personasPage(engagement: PersonaEngagement::High))->toBe([$active->id])
        ->and(personasPage(engagement: PersonaEngagement::Off))->toEqualCanonicalizing([$shadowbanned->id, $unprofiled->id])
        ->and(personasPage(status: UserStatus::Active, engagement: PersonaEngagement::Low))->toBe([]);
});

it('searches the username, the names, the short description and the character, literally and in any case', function () {
    $byUsername = listedPersona(['username' => 'night_owl']);
    $byName = listedPersona(['name' => 'Gregor Samsa', 'display_name' => 'Gregor Samsa']);
    $byShort = listedPersona([], ['short_description' => 'Sarcastic one-liners']);
    $byCharacter = listedPersona([], ['character' => 'Grumpy but secretly kind']);
    listedPersona(['username' => 'unrelated'], ['short_description' => 'Calm', 'character' => 'Quiet']);

    expect(personasPage('NIGHT'))->toBe([$byUsername->id])
        ->and(personasPage('@night_owl'))->toBe([$byUsername->id])
        ->and(personasPage('samsa'))->toBe([$byName->id])
        ->and(personasPage('one-liners'))->toBe([$byShort->id])
        ->and(personasPage('secretly'))->toBe([$byCharacter->id])
        ->and(personasPage('night%'))->toBe([])
        ->and(personasPage('n_ght'))->toBe([]);
});

it('does not search what Content Studio keeps besides, nor anyone who is no persona', function () {
    listedPersona([], ['topics' => 'Hidden topic', 'never_writes_about' => 'Hidden limit']);
    User::factory()->create(['username' => 'hidden_member']);

    expect(personasPage('hidden'))->toBe([]);
});

it('counts every persona, the active ones, the restricted ones and those that would reply', function () {
    listedPersona([], ['engagement' => PersonaEngagement::Normal]);
    listedPersona([], ['engagement' => PersonaEngagement::Off]);
    listedPersona(['status' => UserStatus::Limited], ['engagement' => PersonaEngagement::High]);
    listedPersona(['status' => UserStatus::Banned]);
    User::factory()->persona()->create();
    User::factory()->persona()->tombstoned()->create();

    expect(app(PersonasQuery::class)->stats())->toBe([
        'total' => 5,
        'active' => 3,
        'restricted' => 2,
        'replying' => 2,
    ]);
});

it('keeps personas made at the same moment in their place across pages', function () {
    $personas = User::factory()->persona()->count(PersonasQuery::PER_PAGE + 5)->create(['created_at' => now()->subDay()->startOfSecond()]);
    $expected = $personas->pluck('id')->sortDesc()->values()->all();

    $first = personasPage();
    $second = personasPage(page: 2);

    expect($first)->toHaveCount(PersonasQuery::PER_PAGE)
        ->and(array_intersect($first, $second))->toBe([])
        ->and([...$first, ...$second])->toBe($expected);
});
