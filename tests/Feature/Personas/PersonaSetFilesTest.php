<?php

use App\Data\Personas\PersonaData;
use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Support\Personas\PersonaFileReader;

/**
 * The Persona sets a project imports (resources/studio/personas): each a file
 * `studio:personas:import` takes whole — every entry valid, every username
 * once — made of characters that differ in what they say and how they say
 * it, every one replying to nobody on its own, and none sounding like a
 * generic assistant.
 */

/** @return array<string, list<PersonaData>> every set, by file name */
function personaSets(): array
{
    $sets = [];

    foreach (glob(resource_path('studio/personas/*.json')) ?: [] as $path) {
        $sets[basename($path)] = app(PersonaFileReader::class)->read($path);
    }

    return $sets;
}

/** The words a text is made of, of three letters or more, without the ones every sentence has. */
function personaTextWords(?string $text): array
{
    preg_match_all('/[a-z]{3,}/', mb_strtolower((string) $text), $matches);

    return array_values(array_diff(array_unique($matches[0]), [
        'and', 'are', 'but', 'every', 'for', 'from', 'her', 'his', 'its', 'never', 'not', 'one', 'she', 'than', 'that', 'the',
        'their', 'them', 'they', 'this', 'two', 'when', 'who', 'with',
    ]));
}

it('keeps every set a file the import takes whole', function () {
    $sets = personaSets();

    expect($sets)->toHaveKey('tits-guru.json');

    foreach ($sets as $personas) {
        expect($personas)->not->toBeEmpty();
    }
});

it('holds a hundred personas for Tits Guru', function () {
    expect(personaSets()['tits-guru.json'])->toHaveCount(100);
});

it('writes Tits Guru’s personas as men, two of them as women', function () {
    $written = array_map(function (PersonaData $persona): string {
        preg_match('/\b(he|she|they)\b/i', (string) $persona->character, $pronoun);

        return mb_strtolower($pronoun[1] ?? 'none');
    }, personaSets()['tits-guru.json']);

    expect(array_count_values($written))->toBe(['he' => 98, 'she' => 2]);
});

it('gives nine in ten Tits Guru usernames as one word, as forum regulars pick them', function () {
    $oneWord = array_filter(personaSets()['tits-guru.json'], fn (PersonaData $persona): bool => preg_match('/^[a-z0-9]+$/', (string) $persona->username) === 1);

    expect(count($oneWord))->toBeGreaterThanOrEqual(90);
});

it('lets no persona reply on its own', function () {
    foreach (personaSets() as $personas) {
        foreach ($personas as $persona) {
            expect($persona->engagement)->toBe(PersonaEngagement::Off, $persona->username)
                ->and($persona->maxRepliesPerDay)->toBe(0, $persona->username);
        }
    }
});

it('gives every persona a name, a public face and a character of its own', function () {
    foreach (personaSets() as $personas) {
        foreach (['displayName', 'bio', 'shortDescription', 'character', 'speechStyle', 'topics', 'neverWritesAbout'] as $field) {
            $values = array_map(fn (PersonaData $persona): string => mb_strtolower((string) $persona->{$field}), $personas);

            expect(array_filter($values, fn (string $value): bool => $value === ''))->toBe([], "{$field} left empty")
                ->and(array_keys(array_filter(array_count_values($values), fn (int $count): bool => $count > 1)))->toBe([], "{$field} repeated");
        }
    }
});

it('makes no two personas alike in character, voice, interests or limits', function () {
    foreach (personaSets() as $personas) {
        foreach (['character', 'speechStyle', 'topics', 'neverWritesAbout', 'shortDescription'] as $field) {
            $alike = [];

            foreach ($personas as $i => $one) {
                foreach (array_slice($personas, $i + 1) as $other) {
                    $a = personaTextWords($one->{$field});
                    $b = personaTextWords($other->{$field});
                    $overlap = count(array_intersect($a, $b)) / max(1, count(array_unique([...$a, ...$b])));

                    if ($overlap > 0.4) {
                        $alike[] = "{$one->username} ~ {$other->username}";
                    }
                }
            }

            expect($alike)->toBe([], "{$field} too alike");
        }
    }
});

it('mixes how often personas use emoji, and names each emoji user’s own', function () {
    foreach (personaSets() as $personas) {
        $counts = array_count_values(array_map(fn (PersonaData $persona): string => $persona->emojiFrequency->value, $personas));

        foreach (PersonaEmojiFrequency::cases() as $frequency) {
            expect($counts[$frequency->value] ?? 0)->toBeGreaterThanOrEqual(intdiv(count($personas), 10), $frequency->value);
        }

        $sets = [];

        foreach ($personas as $persona) {
            preg_match_all('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', (string) $persona->speechStyle, $emoji);

            if ($persona->emojiFrequency !== PersonaEmojiFrequency::None) {
                expect($emoji[0])->not->toBeEmpty("{$persona->username} names no emoji of its own");
                $own = array_unique($emoji[0]);
                sort($own);
                $sets[] = implode('', $own);
            }
        }

        expect(array_keys(array_filter(array_count_values($sets), fn (int $count): bool => $count > 1)))->toBe([], 'emoji sets shared');
    }
});

it('never sounds like a generic assistant or an advert', function () {
    $stock = ['absolutely stunning', 'what a beautiful picture', 'wow, just wow', 'amazing content', 'love this', 'breathtaking', 'living for this', 'captain obvious'];

    foreach (personaSets() as $personas) {
        foreach ($personas as $persona) {
            $text = mb_strtolower(implode(' ', array_filter($persona->toArray(), is_string(...))));

            foreach ($stock as $phrase) {
                expect($text)->not->toContain($phrase, "{$persona->username}: {$phrase}");
            }
        }
    }
});
