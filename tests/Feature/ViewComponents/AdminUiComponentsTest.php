<?php

use Illuminate\View\ViewException;

/**
 * The x-admin.ui.* components: the semantics and accessibility baseline every
 * Admin v2 screen inherits from them.
 */
it('draws decorative icons hidden from assistive technology, and named ones as images', function () {
    $this->blade('<x-admin.ui.icon name="flag" />')
        ->assertSee('aria-hidden="true"', false)
        ->assertSee('stroke-width="1.75"', false)
        ->assertDontSee('role="img"', false);

    $this->blade('<x-admin.ui.icon name="hard-drive" :size="18" label="Media diagnostics" />')
        ->assertSee('role="img"', false)
        ->assertSee('aria-label="Media diagnostics"', false)
        ->assertSee('width="18"', false)
        ->assertDontSee('aria-hidden', false);
});

it('fails loudly on an icon it does not have', function () {
    $this->blade('<x-admin.ui.icon name="no-such-icon" />');
})->throws(ViewException::class, 'Unknown admin icon [no-such-icon]');

it('renders buttons as real buttons, links as links, and disabled ones as disabled', function () {
    $this->blade('<x-admin.ui.button variant="primary">Save changes</x-admin.ui.button>')
        ->assertSee('<button type="button"', false)
        ->assertSee('rg-admin-button--primary', false);

    $this->blade('<x-admin.ui.button href="/admin/languages">Open</x-admin.ui.button>')
        ->assertSee('<a href="/admin/languages"', false);

    $this->blade('<x-admin.ui.button href="/admin/languages" disabled>Open</x-admin.ui.button>')
        ->assertSee('disabled', false)
        ->assertDontSee('href=', false);

    $this->blade('<x-admin.ui.button variant="danger" size="sm" icon="check">Finalize removal</x-admin.ui.button>')
        ->assertSee('rg-admin-button--danger', false)
        ->assertSee('rg-admin-button--sm', false)
        ->assertSee('width="14"', false);
});

it('offers exactly the button variants of the contract', function (string $variant) {
    $this->blade('<x-admin.ui.button :variant="$variant">Go</x-admin.ui.button>', ['variant' => $variant])
        ->assertSee("rg-admin-button--{$variant}", false);
})->with(['primary', 'secondary', 'ghost', 'danger']);

it('rejects a button variant outside the contract', function () {
    $this->blade('<x-admin.ui.button variant="link">Go</x-admin.ui.button>');
})->throws(ViewException::class, 'Unknown admin button variant [link]');

it('names icon-only buttons for screen readers and as a tooltip', function () {
    $this->blade('<x-admin.ui.icon-button icon="ellipsis" label="More actions" variant="ghost" size="sm" />')
        ->assertSee('aria-label="More actions"', false)
        ->assertSee('title="More actions"', false)
        ->assertSee('<button type="button"', false);
});

it('draws badges by tone only, with a decorative dot', function (string $tone) {
    $this->blade('<x-admin.ui.badge :tone="$tone" dot>Label</x-admin.ui.badge>', ['tone' => $tone])
        ->assertSee("rg-admin-badge--{$tone}", false)
        ->assertSee('<span class="rg-admin-badge__dot" aria-hidden="true"></span>', false)
        ->assertSee('Label');
})->with(['success', 'warning', 'danger', 'neutral', 'outline', 'info']);

it('knows tones, not statuses', function () {
    $this->blade('<x-admin.ui.badge tone="published">Published</x-admin.ui.badge>');
})->throws(ViewException::class, 'Unknown admin badge tone [published]');

it('ties a text field to its label and its hint', function () {
    $this->blade('<x-admin.ui.text-field label="Name" name="name" hint="Shown on the public profile." :required="true" />')
        ->assertSee('<label for="rg-admin-field-name"', false)
        ->assertSee('id="rg-admin-field-name"', false)
        ->assertSee('aria-describedby="rg-admin-field-name-hint"', false)
        ->assertSee('required', false)
        ->assertSee('Required');
});

it('marks an invalid text field and says what to do instead of the hint', function () {
    $this->blade('<x-admin.ui.text-field label="Username" name="username" hint="Public as @biscuit_mum." error="biscuit_mum is already taken." />')
        ->assertSee('aria-invalid="true"', false)
        ->assertSee('aria-describedby="rg-admin-field-username-error"', false)
        ->assertSee('biscuit_mum is already taken.')
        ->assertDontSee('Public as @biscuit_mum.');
});

it('keeps a caller\'s own description alongside the field\'s hint', function () {
    $view = (string) $this->blade('<x-admin.ui.text-field label="Name" name="name" hint="Shown publicly." aria-describedby="name-policy" />');

    expect(substr_count($view, 'aria-describedby='))->toBe(1)
        ->and($view)->toContain('aria-describedby="name-policy rg-admin-field-name-hint"');

    $view = (string) $this->blade('<x-admin.ui.textarea label="Note" name="note" :limit="10" aria-describedby="note-policy" />');

    expect(substr_count($view, 'aria-describedby='))->toBe(1)
        ->and($view)->toContain('aria-describedby="note-policy rg-admin-field-note-counter"');
});

it('counts characters the way the server does, as code points', function () {
    // mb_strlen on the server and Array.from in the browser both count an
    // emoji as one character; a UTF-16 .length would count it as two.
    $this->blade('<x-admin.ui.textarea label="Note" name="note" :limit="10" value="🐶 Rex" />')
        ->assertSee('5 / 10')
        ->assertSee('length = Array.from($event.target.value).length', false);
});

it('really disables a disabled field', function () {
    $this->blade('<x-admin.ui.text-field label="Email" name="email" disabled />')
        ->assertSee('rg-admin-input--disabled', false)
        ->assertSee('disabled', false);
});

it('counts a textarea against its limit and marks it once over', function () {
    $this->blade('<x-admin.ui.textarea label="Reason" name="reason" :limit="500" :required="false" />')
        ->assertSee('0 / 500')
        ->assertSee('Optional')
        ->assertSee('aria-describedby="rg-admin-field-reason-counter"', false);

    $this->blade('<x-admin.ui.textarea label="Label" name="label" :limit="4" value="Too long" error="4 over the limit" />')
        ->assertSee('8 / 4')
        ->assertSee('rg-admin-field__counter--over', false)
        ->assertSee('rg-admin-textarea--invalid', false)
        ->assertSee('aria-describedby="rg-admin-field-label-counter rg-admin-field-label-error"', false);
});

it('draws unsaved drafts in their own textarea tones', function (string $tone) {
    $this->blade('<x-admin.ui.textarea label="Tagline" name="tagline" :tone="$tone" />', ['tone' => $tone])
        ->assertSee("rg-admin-textarea--{$tone}", false);
})->with(['info', 'changed']);

it('labels a search field by what it searches and hands bindings to the input', function () {
    $this->blade('<x-admin.ui.search-field placeholder="Search title, author or post ID" wire:model.live="search" class="rg-admin-toolbar__search" />')
        ->assertSee('aria-label="Search title, author or post ID"', false)
        ->assertSee('type="search"', false)
        ->assertSee('wire:model.live="search"', false)
        ->assertSee('<label class="rg-admin-search rg-admin-toolbar__search">', false);
});

it('renders status tabs as links with the current one marked, or as toggles', function () {
    $links = $this->blade('<x-admin.ui.tabs label="Post status" active="pending" :items="$items" />', ['items' => [
        ['id' => 'all', 'label' => 'All', 'count' => '1,262', 'href' => '?status=all'],
        ['id' => 'pending', 'label' => 'Pending', 'count' => 8, 'href' => '?status=pending'],
    ]])->assertSee('<nav aria-label="Post status"', false);

    expect((string) $links)
        ->toMatch('/href="\?status=pending" class="rg-admin-tab"\s+aria-current="page"/')
        ->not->toMatch('/href="\?status=all" class="rg-admin-tab"\s+aria-current/');

    $this->blade('<x-admin.ui.tabs active="missing" :items="$items" />', ['items' => [
        ['id' => 'missing', 'label' => 'Missing only'],
        ['id' => 'all', 'label' => 'All'],
    ]])
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('aria-pressed="false"', false);
});

it('lets an in-place filter wire each toggle tab to its action', function () {
    $this->blade('<x-admin.ui.tabs active="missing" :items="$items" />', ['items' => [
        ['id' => 'missing', 'label' => 'Missing only', 'attributes' => ['wire:click' => "\$set('mode', 'missing')"]],
        ['id' => 'all', 'label' => 'All', 'attributes' => ['wire:click' => "\$set('mode', 'all')"]],
    ]])
        ->assertSee('class="rg-admin-tab" wire:click="$set(\'mode\', \'missing\')" aria-pressed="true"', false)
        ->assertSee('class="rg-admin-tab" wire:click="$set(\'mode\', \'all\')" aria-pressed="false"', false);
});

it('gives every notice tone its own icon, so the tone is not carried by colour alone', function (string $tone, string $path) {
    $this->blade('<x-admin.ui.inline-notice :tone="$tone">Explained.</x-admin.ui.inline-notice>', ['tone' => $tone])
        ->assertSee("rg-admin-notice--{$tone}", false)
        ->assertSee($path, false);
})->with([
    ['info', '<path d="M12 16v-4"/>'],
    ['warning', '<line x1="12" x2="12" y1="8" y2="12"/>'],
    ['danger', '<path d="M12 9v4"/>'],
    ['success', '<path d="m9 12 2 2 4-4"/>'],
]);

it('tells a finished queue from an empty search', function () {
    $this->blade('<x-admin.ui.empty-state icon="circle-check" tone="success" title="The queue is clear">Every post has been reviewed.</x-admin.ui.empty-state>')
        ->assertSee('rg-admin-empty-state__icon--success', false)
        ->assertSee('<h3 class="rg-admin-empty-state__title">The queue is clear</h3>', false)
        ->assertSee('Every post has been reviewed.');
});

it('hides skeleton bars from assistive technology', function () {
    $this->blade('<x-admin.ui.skeleton width="62%" height="10px" />')
        ->assertSee('aria-hidden="true"', false)
        ->assertSee('width: 62%; height: 10px;', false);
});

it('keeps a skeleton\'s size when the caller adds a style of its own', function () {
    $view = (string) $this->blade('<x-admin.ui.skeleton width="40px" height="40px" style="margin-top: 4px" />');

    expect(substr_count($view, 'style='))->toBe(1)
        ->and($view)->toContain('margin-top: 4px')
        ->toContain('width: 40px')
        ->toContain('height: 40px');
});

it('draws a card with a header, a body and a read-only footer', function () {
    $this->blade('<x-admin.ui.card title="Profile" description="Public identity."><p>Fields</p><x-slot:footer>Translations are edited in Translation Center.</x-slot:footer></x-admin.ui.card>')
        ->assertSeeInOrder(['rg-admin-card__header', 'Profile', 'Public identity.', 'rg-admin-card__body', 'Fields', 'rg-admin-card__footer', 'Translations are edited in Translation Center.'], false);
});
