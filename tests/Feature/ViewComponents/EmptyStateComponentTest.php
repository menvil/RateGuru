<?php

use Illuminate\Support\Facades\Blade;

it('renders the title and description', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.empty-state
            title="Nothing to review"
            description="New items will appear here when they need moderation."
        />
    BLADE);

    expect($html)
        ->toContain('Nothing to review')
        ->toContain('New items will appear here when they need moderation.');
});

it('supports an action slot', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.empty-state
            title="No results"
            description="Try another search query."
        >
            <x-slot:action>
                <button type="button">Reset filters</button>
            </x-slot:action>
        </x-ui.empty-state>
    BLADE);

    expect($html)->toContain('Reset filters');
});
