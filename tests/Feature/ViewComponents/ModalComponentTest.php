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
        ->toContain('aria-label="'.__('ui.a11y.close').'"')
        ->toContain('x-on:click="open = false"');
});

it('stops modal clicks from bubbling to clickable parents', function () {
    $html = Blade::render('<x-ui.modal title="Share">Share content</x-ui.modal>');

    expect($html)
        ->toContain('x-on:click.stop')
        ->toContain('x-on:click.stop="open = false"');
});

it('can render a modal panel with visible overflow for dropdown menus', function () {
    $html = Blade::render('<x-ui.modal title="Upload" allow-overflow>Upload content</x-ui.modal>');

    expect($html)
        ->toContain('overflow-visible')
        ->not->toContain('overflow-hidden rounded-rgCard');
});

it('keeps the opt-in dialog behaviours off unless a modal asks for them', function () {
    $html = Blade::render('<x-ui.modal title="Report issue">Report content</x-ui.modal>');

    expect($html)
        ->toContain('class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-6"')
        ->not->toContain('data-modal-below-header')
        ->not->toContain('rgPlaceModalBelowHeader')
        ->not->toContain('x-trap')
        ->not->toContain('keydown.escape')
        ->not->toContain('max-h-[calc(100dvh-3rem)]')
        ->not->toContain('overflow-y-auto overscroll-contain')
        ->toContain('class="px-5 py-4 text-sm text-rg-text2"');
});

it('traps focus and locks page scroll when asked to', function () {
    $html = Blade::render('<x-ui.modal title="Sign in" state="authOpen" trap-focus>Content</x-ui.modal>');

    expect($html)->toContain('x-trap.noscroll.noautofocus="authOpen"')
        ->not->toContain('keydown.escape');
});

it('closes on Escape when asked to', function () {
    $html = Blade::render('<x-ui.modal title="Sign in" state="authOpen" close-on-escape>Content</x-ui.modal>');

    expect($html)->toContain('x-on:keydown.escape.window="authOpen = false"')
        ->not->toContain('x-trap');
});

it('fits the viewport with a fixed header and a scrollable body when asked to', function () {
    $html = Blade::render('<x-ui.modal title="Sign in" fit-viewport>Content</x-ui.modal>');

    expect($html)
        ->toContain('flex max-h-[calc(100dvh-3rem)] flex-col')
        ->toContain('px-5 py-4 shrink-0')
        ->toContain('min-h-0 overflow-y-auto overscroll-contain')
        ->toContain('data-testid="modal-body"');
});

it('accepts a title slot for a heading that changes with the dialog state', function () {
    $html = Blade::render(
        '<x-ui.modal>
            <x-slot:title><span x-show="mode === \'login\'">Log in</span></x-slot:title>
            Content
        </x-ui.modal>',
    );

    expect($html)->toContain('<span x-show="mode === \'login\'">Log in</span>');
});

it('lays a dialog out below the app header when asked to', function () {
    $html = Blade::render('<x-ui.modal title="Photo" state="imageOpen" size="fullscreen" below-header>Image</x-ui.modal>');

    expect($html)
        ->toContain('data-modal-below-header')
        ->toContain('top-[var(--rg-modal-top,0px)]')
        ->toContain('x-effect="if (imageOpen) { $nextTick(() => rgPlaceModalBelowHeader($el)) }"')
        ->toContain('x-on:resize.window="if (imageOpen) { rgPlaceModalBelowHeader($el) }"')
        ->toContain('data-modal-panel')
        ->toContain('data-modal-body')
        ->not->toContain('class="fixed inset-0 z-50');
});

it('opts every image viewer into the below-header layout', function (string $view) {
    expect(file_get_contents(resource_path('views/'.$view)))
        ->toContain('<x-ui.modal title="{{ $post->title }}" state="imageOpen" size="fullscreen" below-header>');
})->with([
    'components/feed/post-card.blade.php',
    'livewire/posts/post-show.blade.php',
    'livewire/feed/post-drawer-content.blade.php',
]);

it('focuses the field marked autofocus when asked to', function () {
    $html = Blade::render('<x-ui.modal title="Delete" state="deleteOpen" trap-focus autofocus><input autofocus></x-ui.modal>');

    expect($html)->toContain('x-trap.noscroll="deleteOpen"')
        ->not->toContain('noautofocus');
});
