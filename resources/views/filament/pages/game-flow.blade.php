@php
    /*
     * A game's Flow tab (EditGameFlow), as the reference draws it: a band with
     * the flow on screen and its menu, Copy flow and Preview; then three
     * panes — the step library (250), the flow's steps in order down the
     * middle (up to 520), a + between two cards offering only the steps that
     * fit there, and the selected step (360): its settings, or the step
     * played as a phone shows it, light or dark. The flow is one draft until Save in the top
     * bar; its problems are listed as it stands.
     */
    $draft = '{ steps: $wire.steps }';
    $saved = '{ steps: $wire.saved }';
@endphp

<div
    class="rg-admin rg-admin-screen rg-admin-games"
    x-data
    x-effect="$store.adminEditor.changes = rgAdminEditorListChanges({{ $draft }}, {{ $saved }}); $store.adminEditor.blocked = $wire.stale"
    x-on:beforeunload.window="if (! $store.adminEditor.leaving && rgAdminEditorListChanges({{ $draft }}, {{ $saved }}) > 0) { $event.preventDefault(); $event.returnValue = '' }"
>
    @include('filament.pages.editor.script')
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-games__flow-band">
        @include('filament.pages.games.flow.flows')

        <x-admin.ui.row-menu id="rg-admin-game-flow-menu" label="More actions for this flow">
            @if ($flowArchived)
                <x-admin.ui.menu-item icon="archive-restore" wire:click="restoreFlow">Restore flow</x-admin.ui.menu-item>
            @else
                <x-admin.ui.menu-item icon="archive" :disabled="$flowOnly" :hint="$flowOnly ? 'A game keeps at least one flow. Add another first.' : 'It can’t be published until it is restored. Versions keep their own copy.'" wire:click="askFlow('archive')">Archive flow</x-admin.ui.menu-item>
            @endif
            <div class="rg-admin-menu__separator" role="separator"></div>
            <x-admin.ui.menu-item icon="trash-2" tone="danger" :disabled="$flowUsed || ($flowOnly && ! $flowArchived)" :hint="$flowUsed ? 'A published version was made from this flow. Archive it instead.' : (($flowOnly && ! $flowArchived) ? 'A game keeps at least one flow.' : null)" wire:click="askFlow('delete')">Delete flow</x-admin.ui.menu-item>
        </x-admin.ui.row-menu>

        <span class="rg-admin-games__flow-band-gap"></span>
        <x-admin.ui.button size="sm" icon="copy" wire:click="createFlow(true)">Copy flow</x-admin.ui.button>
        <x-admin.ui.button size="sm" icon="play" :href="$fullPreviewUrl">Preview</x-admin.ui.button>
    </div>

    <div class="rg-admin-games__flowspace">
        <aside class="rg-admin-games__pane rg-admin-games__pane--library" aria-label="Steps">
            <div class="rg-admin-games__pane-inner">
                @include('filament.pages.games.flow.library')
            </div>
        </aside>

        <section class="rg-admin-games__flow-stage" aria-label="Flow">
            <div class="rg-admin-games__flow-stage-inner">
                @include('filament.pages.games.flow.canvas')
            </div>
        </section>

        {{-- The step preview's theme lives on the pane, so it stays as chosen from one step to the next. --}}
        <aside
            class="rg-admin-games__pane rg-admin-games__pane--step"
            aria-label="Step settings"
            x-data="{
                theme: null,
                apply() {
                    const root = this.$refs.frame?.contentDocument?.documentElement

                    if (! root) {
                        return
                    }

                    this.theme ??= root.dataset.theme === 'dark' ? 'dark' : 'light'
                    root.dataset.theme = this.theme
                },
            }"
            x-init="$watch('theme', () => apply())"
        >
            <div class="rg-admin-games__pane-inner">
                @include('filament.pages.games.flow.settings')
            </div>
        </aside>
    </div>

    @if ($confirmingFlow !== null)
        <x-admin.ui.confirm-dialog
            id="rg-admin-game-flow"
            :tone="$confirmingFlow === 'delete' ? 'danger' : 'warning'"
            :icon="$confirmingFlow === 'delete' ? 'trash-2' : 'archive'"
            :title="$confirmingFlow === 'delete' ? 'Delete this flow?' : 'Archive this flow?'"
            x-on:dismiss="$wire.closeFlow()"
        >
            <p>{{ $confirmingFlow === 'delete' ? 'Its steps and their texts go with it.' : 'It can no longer be published until it is restored. Published versions keep their own copy.' }}</p>
            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                <x-admin.ui.button :variant="$confirmingFlow === 'delete' ? 'danger' : 'primary'" wire:click="confirmFlow" x-on:click="$store.adminEditor.leaving = true">{{ $confirmingFlow === 'delete' ? 'Delete flow' : 'Archive flow' }}</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
