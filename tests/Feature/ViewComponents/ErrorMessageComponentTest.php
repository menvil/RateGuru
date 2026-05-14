<?php

use Illuminate\Support\Facades\Blade;

it('renders the title and message', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.error-message
            title="Access denied"
            message="You do not have permission to perform this action."
        />
    BLADE);

    expect($html)
        ->toContain('Access denied')
        ->toContain('You do not have permission to perform this action.');
});

it('supports an action slot', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.error-message
            title="Loading failed"
            message="Please try again in a moment."
        >
            <x-slot:action>
                <button type="button">Retry</button>
            </x-slot:action>
        </x-ui.error-message>
    BLADE);

    expect($html)->toContain('Retry');
});

it('uses danger styling for a general error block', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.error-message
            title="Action failed"
            message="The operation could not be completed."
        />
    BLADE);

    expect($html)
        ->toContain('border-[rgba(239,68,68,0.45)]')
        ->toContain('bg-[rgba(239,68,68,0.12)]')
        ->toContain('text-[#fca5a5]');
});
