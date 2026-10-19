{{--
    An editor page saves from the top bar (NAV-02): its unsaved state in the
    due colour, Discard to put the saved values back, and Save — which also
    waits while the page says the draft cannot be saved. The page keeps the
    `adminEditor` store this reads in step (filament.pages.editor.script) and
    listens for the events these buttons dispatch.

    $unsaved is the note while anything is unsaved: null counts the changes
    (“3 unsaved changes”); a string says it in words of its own.
    $savesUnchanged lets Save run with nothing changed — a new record that
    may be saved empty — still waiting while the page says it cannot.
--}}
@php($savesUnchanged ??= false)
<div class="rg-admin-topbar__editor" x-data>
    @if ($unsaved === null)
        <span class="rg-admin-topbar__unsaved" x-cloak x-show="$store.adminEditor?.changes > 0" x-text="`${$store.adminEditor?.changes} unsaved ${$store.adminEditor?.changes === 1 ? 'change' : 'changes'}`"></span>
    @else
        <span class="rg-admin-topbar__unsaved" x-cloak x-show="$store.adminEditor?.changes > 0">{{ $unsaved }}</span>
    @endif
    <x-admin.ui.button disabled x-bind:disabled="! ($store.adminEditor?.changes > 0)" x-on:click="$dispatch('{{ $discard }}')">Discard</x-admin.ui.button>
    @if ($savesUnchanged)
        <x-admin.ui.button variant="primary" x-bind:disabled="$store.adminEditor?.blocked" x-on:click="$dispatch('{{ $save }}')">{{ $label }}</x-admin.ui.button>
    @else
        <x-admin.ui.button variant="primary" disabled x-bind:disabled="! ($store.adminEditor?.changes > 0) || $store.adminEditor?.blocked" x-on:click="$dispatch('{{ $save }}')">{{ $label }}</x-admin.ui.button>
    @endif
</div>
