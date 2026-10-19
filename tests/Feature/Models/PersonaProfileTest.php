<?php

use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Enums\UserRole;
use App\Models\PersonaProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What Content Studio keeps about a Persona: one row per account, closed sets
 * the database itself holds to, defaults that reply to nobody, and nothing of
 * it ever serialized with the account.
 */
it('belongs to one account, and an account has at most one', function () {
    $profile = PersonaProfile::factory()->create();

    expect($profile->user)->toBeInstanceOf(User::class)
        ->and($profile->user->personaProfile->is($profile))->toBeTrue();

    expect(fn () => PersonaProfile::factory()->create(['user_id' => $profile->user_id]))
        ->toThrow(QueryException::class);
});

it('does not make an account a persona by being there', function () {
    $member = User::factory()->create();
    PersonaProfile::factory()->create(['user_id' => $member->id]);

    expect($member->fresh())->role->toBe(UserRole::User)
        ->and($member->fresh()->isPersona())->toBeFalse();
});

it('replies to nobody by default', function () {
    $persona = User::factory()->persona()->create();
    DB::table('persona_profiles')->insert(['user_id' => $persona->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(PersonaProfile::query()->where('user_id', $persona->id)->firstOrFail())
        ->engagement->toBe(PersonaEngagement::Off)
        ->max_replies_per_day->toBe(0)
        ->emoji_frequency->toBe(PersonaEmojiFrequency::None)
        ->short_description->toBeNull()
        ->character->toBeNull()
        ->and(new PersonaProfile)
        ->engagement->toBe(PersonaEngagement::Off)
        ->max_replies_per_day->toBe(0);
});

it('lets the database itself refuse a level outside the closed sets', function (string $column, string $value) {
    $persona = User::factory()->persona()->create();

    expect(fn () => DB::table('persona_profiles')->insert([
        'user_id' => $persona->id,
        $column => $value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([
    'an engagement level' => ['engagement', 'always'],
    'an emoji frequency' => ['emoji_frequency', 'constant'],
]);

it('remembers its creator through an edit of the creator, and lets go only if the row itself goes', function () {
    $admin = User::factory()->admin()->create();
    $profile = PersonaProfile::factory()->create(['created_by_user_id' => $admin->id]);

    $admin->update(['name' => 'Renamed Admin']);

    expect($profile->fresh()->createdBy->is($admin))->toBeTrue();

    DB::table('users')->where('id', $admin->id)->delete();

    expect($profile->fresh()->created_by_user_id)->toBeNull();
});

it('serializes none of its instructions with the account', function () {
    $persona = PersonaProfile::factory()->create([
        'short_description' => 'SECRET-SHORT',
        'character' => 'SECRET-CHARACTER',
        'speech_style' => 'SECRET-STYLE',
        'topics' => 'SECRET-TOPICS',
        'never_writes_about' => 'SECRET-LIMITS',
    ])->user->load('personaProfile');

    $json = $persona->toJson().json_encode($persona->personaProfile->toArray());

    foreach (['SECRET-SHORT', 'SECRET-CHARACTER', 'SECRET-STYLE', 'SECRET-TOPICS', 'SECRET-LIMITS', 'engagement', 'max_replies_per_day', 'emoji_frequency', 'created_by_user_id'] as $internal) {
        expect($json)->not->toContain($internal);
    }
});

it('has the columns its contract names, with no geography, addresses or schedule', function () {
    expect(Schema::getColumnListing('persona_profiles'))->toEqualCanonicalizing([
        'id', 'user_id', 'short_description', 'character', 'speech_style', 'topics', 'never_writes_about',
        'emoji_frequency', 'engagement', 'max_replies_per_day', 'created_by_user_id', 'created_at', 'updated_at',
    ]);
});
