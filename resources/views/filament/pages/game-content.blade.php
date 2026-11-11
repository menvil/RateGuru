@php
    /*
     * A game's Content tab (EditGameContent), in three panes: the content set
     * switcher and the set's rounds down the left; the round on screen — or
     * the set's Results — in the middle; and the round's live preview on the
     * right, the site's own game screen as a phone shows it, unsaved edits
     * included. Below 1360 the preview opens over the page from Preview round.
     * The whole set is one draft until Save in the top bar; the browser counts
     * what is unsaved (filament.pages.editor.script) and asks before the page
     * is left with unsaved changes. Each round says, as it stands, what keeps
     * it from being published.
     */
    // Rounds compare by their key, and their order as one value: moving a round is one change.
    $order = fn (string $rounds): string => "({$rounds} ?? []).map((round) => round.key).join()";
    $byKey = fn (string $rounds): string => "Object.fromEntries(({$rounds} ?? []).map((round) => [round.key, round]))";
    $draft = '{ scoring: $wire.scoring, order: '.$order('$wire.rounds').', rounds: '.$byKey('$wire.rounds').', profiles: $wire.profiles, uploads: Object.keys($wire.uploads ?? {}).filter((key) => $wire.uploads[key]).length }';
    $saved = '{ ...$wire.saved, order: '.$order('$wire.saved.rounds').', rounds: '.$byKey('$wire.saved.rounds').', uploads: 0 }';
    $live = $liveUrl !== null && $pane === 'rounds';
@endphp

<div
    class="rg-admin rg-admin-screen rg-admin-games"
    x-data="{ preview: false }"
    x-on:keydown.escape.window="preview = false"
    x-effect="$store.adminEditor.changes = rgAdminEditorListChanges({{ $draft }}, {{ $saved }}); $store.adminEditor.blocked = $wire.stale"
    x-on:beforeunload.window="if (! $store.adminEditor.leaving && rgAdminEditorListChanges({{ $draft }}, {{ $saved }}) > 0) { $event.preventDefault(); $event.returnValue = '' }"
>
    @include('filament.pages.editor.script')
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div @class(['rg-admin-games__workspace', 'rg-admin-games__workspace--live' => $live])>
        <aside class="rg-admin-games__pane rg-admin-games__pane--rounds" aria-label="Content set">
            <div class="rg-admin-games__pane-inner">
                <div class="rg-admin-games__pane-top">
                    @include('filament.pages.games.content.sets')
                </div>
                <div class="rg-admin-games__pane-mode">
                    <x-admin.ui.segmented
                        label="Content"
                        :options="['rounds' => 'Rounds', 'results' => 'Results'.($resultIssues > 0 ? ' · '.$resultIssues.' to fix' : '')]"
                        :value="$pane"
                        class="rg-admin-games__pane-switch"
                        wire:model.live="pane"
                    />
                    <span class="rg-admin-games__note">Results belong to this set: profiles and score ranges for its rounds.</span>
                </div>
                @include('filament.pages.games.content.rounds')
            </div>
        </aside>

        <section class="rg-admin-games__stage" aria-live="polite">
            <div class="rg-admin-games__stage-inner">
                @if ($stale)
                    <x-admin.ui.inline-notice tone="warning">
                        Someone saved this content set after you opened it. Reload to see their changes; what you changed here is replaced.
                        <x-slot:actions>
                            <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload</x-admin.ui.button>
                        </x-slot:actions>
                    </x-admin.ui.inline-notice>
                @endif

                @if ($setArchived)
                    <x-admin.ui.inline-notice tone="info">
                        This content set is archived. It can’t be published until it is restored.
                        <x-slot:actions>
                            <x-admin.ui.button size="sm" icon="archive-restore" wire:click="restoreSet">Restore set</x-admin.ui.button>
                        </x-slot:actions>
                    </x-admin.ui.inline-notice>
                @endif

                @if ($pane === 'results')
                    @include('filament.pages.games.content.results')
                @elseif ($round !== null)
                    @include('filament.pages.games.content.round')
                @else
                    <x-admin.ui.card>
                        <x-admin.ui.empty-state icon="columns-2" title="Add the first round">
                            A round is a question with two images. Five rounds is a good length for acquisition traffic.
                            <x-slot:action>
                                <x-admin.ui.button size="sm" variant="primary" icon="plus" wire:click="addRound">Add round</x-admin.ui.button>
                            </x-slot:action>
                        </x-admin.ui.empty-state>
                    </x-admin.ui.card>
                @endif
            </div>
        </section>

        @if ($live)
            @include('filament.pages.games.content.live-preview')
        @endif
    </div>

    @if ($picker !== null)
        @include('filament.pages.games.content.picker')
    @endif

    @if ($editing !== null)
        @include('filament.pages.games.content.profile-drawer')
    @endif

    @if ($confirmingSet !== null)
        <x-admin.ui.confirm-dialog
            id="rg-admin-game-set"
            :tone="$confirmingSet === 'delete' ? 'danger' : 'warning'"
            :icon="$confirmingSet === 'delete' ? 'trash-2' : 'archive'"
            :title="$confirmingSet === 'delete' ? 'Delete this content set?' : 'Archive this content set?'"
            x-on:dismiss="$wire.closeSet()"
        >
            <p>{{ $confirmingSet === 'delete' ? 'Its rounds and profiles go with it. Pictures only it shows are released.' : 'It can no longer be published until it is restored. Published versions keep their own copy.' }}</p>
            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                <x-admin.ui.button :variant="$confirmingSet === 'delete' ? 'danger' : 'primary'" wire:click="confirmSet" x-on:click="$store.adminEditor.leaving = true">{{ $confirmingSet === 'delete' ? 'Delete set' : 'Archive set' }}</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
