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

it('keeps an icon with a blank label decorative', function () {
    $this->blade('<x-admin.ui.icon name="flag" label="" />')
        ->assertSee('aria-hidden="true"', false)
        ->assertDontSee('role="img"', false)
        ->assertDontSee('<title>', false);
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

    expect((string) $this->blade('<x-admin.ui.button href="/admin/languages" disabled>Open</x-admin.ui.button>'))
        ->toMatch('/<button\b[^>]*\sdisabled[\s>\/]/')
        ->not->toContain('href=');

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

    // "0" is a valid ID reference and must survive the merge.
    expect((string) $this->blade('<x-admin.ui.text-field label="Code" name="code" aria-describedby="0" />'))
        ->toContain('aria-describedby="0"');
});

it('counts characters the way the server does, as code points', function () {
    // mb_strlen on the server and Array.from in the browser both count an
    // emoji as one character; a UTF-16 .length would count it as two.
    $this->blade('<x-admin.ui.textarea label="Note" name="note" :limit="10" value="🐶 Rex" />')
        ->assertSee('5 / 10')
        ->assertSee('length = Array.from($event.target.value).length', false);
});

it('really disables a disabled field', function () {
    $view = (string) $this->blade('<x-admin.ui.text-field label="Email" name="email" disabled />');

    // The input itself carries the attribute; the frame's class alone would not disable anything.
    expect($view)->toContain('rg-admin-input--disabled')
        ->toMatch('/<input\b[^>]*\sdisabled[\s>\/]/');
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

it('gives a clearable search field a clear button for its input, in a frame that is not a label', function () {
    $html = (string) $this->blade('<x-admin.ui.search-field placeholder="Search language or locale code" id="rg-admin-languages-search" x-model="query" class="rg-admin-toolbar__search" clearable />');

    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $frame = $xpath->query('//*[contains(@class, "rg-admin-search ")]')->item(0);
    $clear = $xpath->query('//button[contains(@class, "rg-admin-search__clear")]')->item(0);

    // A button may not sit inside a label, so the frame is a div of its own.
    expect($frame?->nodeName)->toBe('div')
        ->and($frame->getAttribute('class'))->toBe('rg-admin-search rg-admin-toolbar__search')
        ->and($frame->hasAttribute('x-data'))->toBeTrue()
        ->and($xpath->query('//label')->length)->toBe(0);

    // The bindings still land on the input, and the button names what it does and what it acts on.
    expect($xpath->query('//input[@id="rg-admin-languages-search"][@x-model="query"][@type="search"]')->length)->toBe(1)
        ->and($clear?->getAttribute('type'))->toBe('button')
        ->and($clear->getAttribute('aria-label'))->toBe('Clear search')
        ->and($clear->getAttribute('aria-controls'))->toBe('rg-admin-languages-search')
        ->and($clear->getAttribute('x-on:click'))->toContain("dispatchEvent(new Event('input', { bubbles: true }))")->toContain('focus()');
});

it('draws no clear button on a search field that does not ask for one', function () {
    expect((string) $this->blade('<x-admin.ui.search-field placeholder="Search" />'))
        ->toContain('<label class="rg-admin-search">')
        ->not->toContain('rg-admin-search__clear')
        ->not->toContain('x-data');
});

it('shows the clear button only while the field holds text', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/components.css'));

    expect($css)->toContain(".rg-admin-search__control:placeholder-shown ~ .rg-admin-search__clear {\n        display: none;\n    }");
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

// Overlays and feedback ------------------------------------------------------------

it('draws a confirmation dialog named by its title and described by its body', function () {
    $view = (string) $this->blade(<<<'BLADE'
        <x-admin.ui.confirm-dialog id="disable-language" title="Disable German?" x-on:dismiss="$wire.closeConfirmation()">
            <p>Visitors currently using German will get their browser's language.</p>
            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="primary">Disable German</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
        BLADE);

    expect($view)
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby="disable-language-title"')
        ->toContain('<h2 id="disable-language-title" class="rg-admin-dialog__title">Disable German?</h2>')
        ->toContain('aria-describedby="disable-language-description"')
        ->toMatch('/id="disable-language-description"[^>]*><p>Visitors currently using German/')
        // The screen hears about a dismissal; it does not have to wire Escape or the scrim itself.
        ->toContain('x-on:dismiss="$wire.closeConfirmation()"')
        ->toContain('x-on:keydown.escape.prevent.stop="dismiss()"')
        ->toContain('x-on:mousedown.self="if ($event.offsetX < $el.clientWidth) dismiss()"')
        // Focus moves in, cannot leave and goes back; the page behind is hidden and still.
        ->toContain('x-trap.inert.noscroll="! closing"')
        // A click on its text keeps focus in the panel, where Escape reaches it.
        ->toMatch('/role="dialog"[^>]*tabindex="-1"/')
        ->toContain('aria-label="Close"')
        ->toMatch('/rg-admin-button--primary[^>]*>\s*Disable German\s*<\/button>/');
});

it('gives each confirmation tone its own circle and icon', function (string $tone, string $path) {
    $this->blade('<x-admin.ui.confirm-dialog :tone="$tone" title="Sure?">Body.</x-admin.ui.confirm-dialog>', ['tone' => $tone])
        ->assertSee("rg-admin-dialog__icon--{$tone}", false)
        ->assertSee($path, false);
})->with([
    'default' => ['default', '<path d="M12 16v-4"/>'],
    'warning' => ['warning', '<line x1="12" x2="12" y1="8" y2="12"/>'],
]);

it('rejects a confirmation tone it does not have', function () {
    $this->blade('<x-admin.ui.confirm-dialog tone="danger" title="Sure?">Body.</x-admin.ui.confirm-dialog>');
})->throws(ViewException::class, 'Unknown admin dialog tone [danger]');

it('puts a dialog\'s details and footnote where the slots say, and a blocked one needs no confirm', function () {
    $view = (string) $this->blade(<<<'BLADE'
        <x-admin.ui.confirm-dialog tone="warning" title="Dogs can’t be deleted">
            612 posts are filed under Dogs.
            <x-slot:details><x-admin.ui.inline-notice>Deactivate it instead.</x-admin.ui.inline-notice></x-slot:details>
            <x-slot:footnote>Nothing has changed.</x-slot:footnote>
            <x-slot:actions><x-admin.ui.button x-on:click="dismiss()">Close</x-admin.ui.button></x-slot:actions>
        </x-admin.ui.confirm-dialog>
        BLADE);

    expect($view)->toMatch('/rg-admin-dialog__details">\s*<div class="rg-admin-notice/')
        ->toMatch('/rg-admin-dialog__footnote">Nothing has changed\.<\/span>/')
        ->not->toContain('rg-admin-button--primary');
});

it('draws a drawer as a labelled dialog with a close button, 448 wide or 480 for long lists', function () {
    $view = (string) $this->blade(<<<'BLADE'
        <x-admin.ui.drawer id="missing" title="Missing in German" subtitle="de · 82% project content" wide x-on:dismiss="$wire.closeMissing()">
            <x-slot:leading>🇩🇪</x-slot:leading>
            <p>Categories</p>
            <x-slot:footer>Visitors see the English text wherever a translation is missing.</x-slot:footer>
        </x-admin.ui.drawer>
        BLADE);

    expect($view)
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby="missing-title"')
        ->toContain('<h2 id="missing-title" class="rg-admin-drawer__title">Missing in German</h2>')
        ->toContain('aria-describedby="missing-subtitle"')
        ->toContain('class="rg-admin-drawer rg-admin-drawer--wide"')
        ->toContain('<span class="rg-admin-drawer__leading" aria-hidden="true">🇩🇪</span>')
        ->toMatch('/rg-admin-drawer__body">\s*<p>Categories<\/p>/')
        ->toMatch('/rg-admin-drawer__footer">Visitors see the English text/')
        ->toContain('class="rg-admin-drawer-scrim" x-on:mousedown="dismiss()" aria-hidden="true"')
        ->toContain('x-on:keydown.escape.prevent.stop="dismiss()"')
        ->toContain('x-trap.inert.noscroll="! closing"')
        ->toMatch('/role="dialog"[^>]*tabindex="-1"/')
        ->toContain('aria-label="Close"');

    expect((string) $this->blade('<x-admin.ui.drawer title="Edit category">Body</x-admin.ui.drawer>'))
        ->toContain('class="rg-admin-drawer"')
        ->not->toContain('aria-describedby');
});

it('announces each toast once, from regions that are always there', function () {
    $view = (string) $this->blade('<x-admin.ui.toast-stack />');

    expect($view)
        ->toContain('aria-label="Notifications"')
        ->toContain('role="status"')
        ->toContain('role="alert"')
        // The toasts themselves are not live regions, so nothing is heard twice.
        ->and(substr_count($view, 'role="status"') + substr_count($view, 'role="alert"'))->toBe(2)
        ->and($view)->not->toContain('aria-live');
});

it('raises a toast from a browser event, keeps three at most, and lets each be dismissed by name', function () {
    $view = (string) $this->blade('<x-admin.ui.toast-stack />');

    expect($view)
        ->toContain('x-on:rg-admin-toast.window="push($event.detail)"')
        ->toContain("['success', 'error', 'info'].includes(detail?.tone) ? detail.tone : 'success'")
        ->toContain('this.toasts = [...this.toasts.slice(-2), toast]')
        ->toContain(': 5200')
        ->toContain('aria-label="Dismiss"')
        ->toContain('aria-label="Success"')
        ->toContain('aria-label="Error"')
        ->toContain('aria-label="Info"')
        // A Livewire re-render around it does not wipe the toasts on screen.
        ->toContain('wire:ignore');

    expect((string) $this->blade('<x-admin.ui.toast-stack :duration="300" />'))->toContain(': 300');
});

it('lays both overlays on one layer that dismisses, hides and finds focus a home', function () {
    $view = (string) $this->blade('<x-admin.ui.overlay class="rg-admin-dialog-layer" x-on:dismiss="open = false">Panel</x-admin.ui.overlay>');

    expect($view)
        ->toContain('class="rg-admin rg-admin-dialog-layer"')
        ->toContain('x-on:dismiss="open = false"')
        ->toContain('x-show="! closing"')
        ->toContain('x-on:keydown.escape.prevent.stop="dismiss()"')
        ->toContain("this.\$dispatch('dismiss')")
        // The opener gone with the change it confirmed: focus goes to the heading, not nowhere.
        ->toContain("document.querySelector('main h1')");
});

it('draws a segmented control as a radio group with one checked option, reachable by Tab', function () {
    $html = (string) $this->blade('<x-admin.ui.segmented label="Show" :options="$options" value="all" x-model="mode" />', [
        'options' => ['missing' => 'Missing only', 'all' => 'All'],
    ]);

    expect($html)
        ->toContain('role="radiogroup"')
        ->toContain('aria-label="Show"')
        ->toContain('x-modelable="selected"')
        ->toContain('x-model="mode"')
        ->toContain('x-on:keydown.arrow-right.prevent="move(1)"')
        ->toContain('x-on:keydown.home.prevent')
        ->and(substr_count($html, 'role="radio"'))->toBe(2)
        // Only the checked option takes Tab; the arrows move the choice.
        ->and($html)->toMatch('/aria-checked="false"\s+tabindex="-1"[^>]*>\s*<span class="rg-admin-segmented__label" data-label="Missing only">/')
        ->and($html)->toMatch('/aria-checked="true"\s+tabindex="0"[^>]*>\s*<span class="rg-admin-segmented__label" data-label="All">/');
});

it('checks the first option of a segmented control whose value is none of them', function () {
    $html = (string) $this->blade('<x-admin.ui.segmented label="Show" :options="[\'missing\' => \'Missing only\', \'all\' => \'All\']" value="everything" />');

    expect($html)->toMatch('/aria-checked="true"\s+tabindex="0"[^>]*>\s*<span class="rg-admin-segmented__label" data-label="Missing only">/');
});

it('holds a segmented control to two to four options', function (array $options) {
    $this->blade('<x-admin.ui.segmented label="Show" :options="$options" />', ['options' => $options]);
})->with([
    'one' => [['all' => 'All']],
    'five' => [['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E']],
])->throws(ViewException::class, 'A segmented control has two to four options.');

it('draws a filter dropdown as a menu button reading “Field: value”, with live counts', function () {
    $html = (string) $this->blade('<x-admin.ui.filter-dropdown id="section" label="Section" :options="$options" value="tags" x-model="section" />', ['options' => [
        ['value' => '', 'label' => 'All sections', 'trigger' => 'All'],
        ['value' => 'categories', 'label' => 'Categories', 'count' => '2 missing'],
        ['value' => 'tags', 'label' => 'Tags', 'count' => '0 missing', 'liveCount' => "missingIn('tags') + ' missing'"],
    ]]);

    expect($html)
        ->toContain('aria-haspopup="menu"')
        ->toContain('aria-expanded="false"')
        ->toContain('aria-controls="section-menu"')
        ->toContain('<span>Section: <span x-text="triggers[selected]">Tags</span></span>')
        ->toContain('id="section-menu"')
        ->toContain('role="menu"')
        ->toContain('aria-labelledby="section"')
        ->toContain('x-modelable="selected"')
        ->toContain('x-on:keydown.escape.prevent.stop="close(true)"')
        ->toContain('<span class="rg-admin-filter-dropdown__count">2 missing</span>')
        ->toContain('x-text="missingIn(&#039;tags&#039;) + &#039; missing&#039;"')
        ->and(substr_count($html, 'role="menuitemradio"'))->toBe(3)
        ->and(substr_count($html, 'aria-checked="true"'))->toBe(1);
});

it('draws a searchable combobox: a trigger with the chosen value, a search over a listbox, and a status for no match', function () {
    $html = (string) $this->blade('<x-admin.ui.combobox id="target" label="Target language" :options="$options" value="de" search-placeholder="Search 2 target languages" empty="No installed language matches." />', ['options' => [
        ['value' => 'ru', 'label' => 'Russian — Русский', 'leading' => '🇷🇺', 'meta' => 'ru · enabled · 0 missing', 'trailing' => '100%', 'trailingTone' => 'success', 'badge' => ['label' => 'Enabled', 'tone' => 'success', 'dot' => true]],
        ['value' => 'de', 'label' => 'German — Deutsch', 'leading' => '🇩🇪', 'meta' => 'de · disabled · 4 missing', 'trailing' => '96%', 'badge' => ['label' => 'Disabled', 'tone' => 'neutral', 'dot' => false]],
    ]]);

    expect($html)
        // The trigger: overline, the chosen value and its state.
        ->toContain('id="target-trigger"')
        ->toContain('aria-haspopup="listbox"')
        ->toContain('aria-controls="target-popover"')
        ->toContain('<span class="rg-admin-combobox__overline">Target language</span>')
        ->toContain('x-text="display[selected]?.label">German — Deutsch</span>')
        ->toContain('rg-admin-badge rg-admin-badge--neutral')
        // The search drives the listbox it filters.
        ->toContain('role="combobox"')
        ->toContain('aria-controls="target-listbox"')
        ->toContain('aria-autocomplete="list"')
        ->toContain('x-bind:aria-activedescendant')
        ->toContain('placeholder="Search 2 target languages"')
        ->toContain('id="target-listbox" role="listbox" aria-label="Target language"')
        ->toContain('rg-admin-combobox__option-trailing--success')
        ->toContain('role="status" x-text="anyShown ? \'\' : \'No installed language matches.\'"')
        ->and(substr_count($html, 'role="option"'))->toBe(2)
        ->and($html)->toMatch('/id="target-option-0"\s+role="option"[^>]*aria-selected="false"/')
        ->and($html)->toMatch('/id="target-option-1"\s+role="option"[^>]*aria-selected="true"/');
});

it('lets a screen decide on a combobox choice before it is taken', function () {
    $html = (string) $this->blade('<x-admin.ui.combobox label="Target language" :options="[[\'value\' => \'ru\', \'label\' => \'Russian\']]" x-on:choose="$event.preventDefault()" />');

    expect($html)
        ->toContain("new CustomEvent('choose', { detail: { value }, bubbles: true, cancelable: true })")
        ->toContain('if (this.$root.dispatchEvent(event))')
        ->toContain('x-on:choose="$event.preventDefault()"');
});
