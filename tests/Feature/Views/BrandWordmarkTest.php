<?php

use App\Models\ProjectSettings;

/*
 * The two-tone wordmark: a CamelCase site name splits at its second word, the
 * tail in the accent colour; a name with no second word is drawn in one tone.
 */
it('splits the site name at its second word, a leading acronym being one word', function (string $name, ?string $head, ?string $tail) {
    ProjectSettings::factory()->create(['site_name' => $name]);

    $html = (string) $this->blade('<x-brand-wordmark />');

    $head === null
        ? expect($html)->not->toContain('text-rg-accent')->toContain($name)
        : expect($html)->toContain($head.'<span class="text-rg-accent">'.$tail.'</span>');
})->with([
    'a preset with an acronym' => ['AIGuru', 'AI', 'Guru'],
    'two words' => ['RateGuru', 'Rate', 'Guru'],
    'one word' => ['Guru', null, null],
]);
