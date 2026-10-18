<?php

use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;

it('offers exactly the engagement levels Content Studio has, Off first', function () {
    expect(array_map(fn (PersonaEngagement $level): string => $level->value, PersonaEngagement::cases()))->toBe(['off', 'low', 'normal', 'high'])
        ->and(array_map(fn (PersonaEngagement $level): string => $level->label(), PersonaEngagement::cases()))->toBe(['Off', 'Low', 'Normal', 'High']);
});

it('offers exactly three emoji frequencies, None first', function () {
    expect(array_map(fn (PersonaEmojiFrequency $level): string => $level->value, PersonaEmojiFrequency::cases()))->toBe(['none', 'occasional', 'frequent'])
        ->and(array_map(fn (PersonaEmojiFrequency $level): string => $level->label(), PersonaEmojiFrequency::cases()))->toBe(['None', 'Occasional', 'Frequent']);
});

it('reads no other value as a level', function () {
    expect(PersonaEngagement::tryFrom('always'))->toBeNull()
        ->and(PersonaEngagement::tryFrom('Off'))->toBeNull()
        ->and(PersonaEmojiFrequency::tryFrom('constant'))->toBeNull();
});
