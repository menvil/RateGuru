<?php

use Illuminate\Support\Facades\Blade;

it('renders a UI modal with title content and footer', function () {
    $html = Blade::render(
        '<x-ui.modal title="Upload dish">
            Upload content
            <x-slot:footer>Footer actions</x-slot:footer>
        </x-ui.modal>',
    );

    expect($html)
        ->toContain('Upload dish')
        ->toContain('Upload content')
        ->toContain('Footer actions');
});

it('renders modal shell accessibility and presentation attributes', function () {
    $html = Blade::render('<x-ui.modal title="Report issue" size="xl">Report content</x-ui.modal>');

    expect($html)
        ->toContain('x-show="open"')
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby="ui-modal-title-')
        ->toContain('Report issue')
        ->toContain('Report content')
        ->toContain('bg-black/70')
        ->toContain('backdrop-blur-sm')
        ->toContain('sm:max-w-xl')
        ->toContain('border-rg-border2')
        ->toContain('aria-label="Close modal"')
        ->toContain('x-on:click="open = false"');
});
