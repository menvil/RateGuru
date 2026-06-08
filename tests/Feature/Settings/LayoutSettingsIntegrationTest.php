<?php

use App\Models\ProjectSettings;

it('renders configured site name in public layout', function () {
    ProjectSettings::factory()->create([
        'site_name' => 'CatGuru',
    ]);

    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('CatGuru');
});

it('renders fallback site name when settings row is missing', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('RateGuru');
});
