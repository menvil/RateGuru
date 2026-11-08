@php
    /*
     * A game's Flow tab (EditGameFlow): the flows down the left with the step
     * library, the flow on screen as cards in order — a + between two cards
     * offers only the steps that fit there — and the selected step's settings
     * on the right. The flow is one draft until Save in the top bar; its
     * problems are listed as it stands.
     */
    $draft = '{ steps: $wire.steps }';
    $saved = '{ steps: $wire.saved }';
@endphp

<div
    class="rg-admin rg-admin-screen rg-admin-games"
    x-data
    x-effect="$store.adminEditor.changes = rgAdminEditorListChanges({{ $draft }}, {{ $saved }}); $store.adminEditor.blocked = $wire.stale"
    x-on:beforeunload.window="if (rgAdminEditorListChanges({{ $draft }}, {{ $saved }}) > 0) { $event.preventDefault(); $event.returnValue = '' }"
>
    @include('filament.pages.editor.script')
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-screen__body">
        @if ($stale)
            <x-admin.ui.inline-notice tone="warning">
                Someone saved this flow after you opened it. Reload to see their changes; what you changed here is replaced.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @endif

        @if ($flowArchived)
            <x-admin.ui.inline-notice tone="info">
                This flow is archived. It can’t be published until it is restored.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="archive-restore" wire:click="restoreFlow">Restore flow</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @endif

        <div class="rg-admin-games__flow">
            <aside class="rg-admin-games__rail" aria-label="Flows">
                @include('filament.pages.games.flow.flows')
                @include('filament.pages.games.flow.library')
            </aside>

            <section class="rg-admin-games__main" aria-label="Steps">
                @if ($issues !== [])
                    <x-admin.ui.inline-notice tone="warning">
                        This flow can’t be published yet.
                        <ul class="rg-admin-games__issues">
                            @foreach ($issues as $issue)
                                <li>{{ $issue->index >= 0 ? 'Step '.($issue->index + 1).' · ' : '' }}{{ $issue->message }}</li>
                            @endforeach
                        </ul>
                    </x-admin.ui.inline-notice>
                @endif
                @include('filament.pages.games.flow.canvas')
            </section>

            <aside class="rg-admin-games__panel" aria-label="Step settings">
                @include('filament.pages.games.flow.settings')
            </aside>
        </div>
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
