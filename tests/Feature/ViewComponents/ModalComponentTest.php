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
        ->toContain('data-testid="modal-backdrop"')
        ->toContain('bg-rg-overlay')
        ->toContain('backdrop-blur-sm')
        ->toContain('motion-safe:transition-opacity')
        ->toContain('sm:max-w-xl')
        ->toContain('rounded-rgCard')
        ->toContain('border-rg-border2')
        ->toContain('aria-label="Close modal"')
        ->toContain('x-on:click="open = false"');
});

it('stops modal clicks from bubbling to clickable parents', function () {
    $html = Blade::render('<x-ui.modal title="Share">Share content</x-ui.modal>');

    expect($html)
        ->toContain('x-on:click.stop')
        ->toContain('x-on:click.stop="open = false"');
});
