@php
    // [icon, tone, text, undo]
    $toasts = [
        ['check', 'success', '3 posts approved and published', true],
        ['circle-alert', 'error', 'PurgeMediaAsset failed again: asset is still referenced', false],
        ['info', 'info', 'Regeneration dispatched for A-209452 · 2 variants', false],
    ];

    // [id, level, note, tone, icon]
    $dialogs = [
        ['light', 'Light', 'A consequence worth a second look · Enable a language', 'default', 'globe'],
        ['warning', 'Warning', 'A risk to accept, with a way out · Enable with content missing', 'warning', 'globe'],
        ['blocked', 'Blocked', 'Explains why and offers the alternative · no confirm', 'warning', 'circle-alert'],
    ];
@endphp

{{-- The live specimens below are Alpine only: opening, confirming and dismissing them changes nothing anywhere. --}}
@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['OVL-01']])
    <div x-data="{ dialog: null }">
        <div class="rg-admin-kit__wrap" style="gap: 12px">
            @foreach ($dialogs as [$id, $level, $note, $tone, $icon])
                <button type="button" class="rg-admin-kit__demo" x-on:click="dialog = @js($id)" data-kit-dialog="{{ $id }}">
                    <span class="rg-admin-dialog__icon rg-admin-dialog__icon--{{ $tone }}"><x-admin.ui.icon :name="$icon" :size="16" /></span>
                    <span class="rg-admin-kit__demo-text">
                        <span class="rg-admin-kit__demo-title">{{ $level }}</span>
                        <span class="rg-admin-kit__demo-note">{{ $note }}</span>
                        <span class="rg-admin-kit__demo-open">Open example →</span>
                    </span>
                </button>
            @endforeach
        </div>

        <template x-if="dialog === 'light'">
            <x-admin.ui.confirm-dialog id="kit-dialog-light" icon="globe" title="Enable German?" x-on:dismiss="dialog = null">
                <p>German has complete project translations and will become available to visitors.</p>
                <x-slot:actions>
                    <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                    <x-admin.ui.button variant="primary" x-on:click="dialog = null">Enable</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.confirm-dialog>
        </template>

        <template x-if="dialog === 'warning'">
            <x-admin.ui.confirm-dialog id="kit-dialog-warning" tone="warning" icon="globe" title="Enable Bulgarian?" x-on:dismiss="dialog = null">
                <p>Bulgarian has 38 missing project translations. Visitors may see English fallback content.</p>
                <x-slot:actions>
                    <x-admin.ui.button x-on:click="dialog = null">Review missing</x-admin.ui.button>
                    <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                    <x-admin.ui.button variant="primary" x-on:click="dialog = null">Enable anyway</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.confirm-dialog>
        </template>

        <template x-if="dialog === 'blocked'">
            <x-admin.ui.confirm-dialog id="kit-dialog-blocked" tone="warning" title="Dogs can’t be deleted" x-on:dismiss="dialog = null">
                <p>612 posts are filed under Dogs. Deleting it would leave them without a category.</p>
                <x-slot:details>
                    <x-admin.ui.inline-notice tone="info">
                        Deactivate it instead: it leaves navigation and the upload form, and existing posts keep it.
                    </x-admin.ui.inline-notice>
                </x-slot:details>
                <x-slot:actions>
                    <x-admin.ui.button x-on:click="dismiss()" autofocus>Close</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.confirm-dialog>
        </template>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['OVL-02']])
    <div x-data="{ drawer: false }" class="rg-admin-kit__stack" style="align-items: flex-start; gap: 8px">
        <x-admin.ui.button x-on:click="drawer = true" data-kit-drawer>Open live drawer</x-admin.ui.button>
        <span class="rg-admin-kit__caption">Opens a 448 px drawer over the scrim. Close it with the scrim, × or Escape.</span>

        <template x-if="drawer">
            <x-admin.ui.drawer id="kit-drawer" title="Edit category" subtitle="OVL-02 · example drawer" x-on:dismiss="drawer = false">
                <div class="rg-admin-kit__stack" style="padding: 20px; gap: 18px">
                    <x-admin.ui.text-field label="Name" name="kit-drawer-name" value="Rabbits & rodents" />
                    <div class="rg-admin-section-label">
                        <span class="rg-admin-section-label__text">Posts</span>
                        <span class="rg-admin-section-label__rule" aria-hidden="true"></span>
                    </div>
                    {{-- Enough rows to show that the body scrolls under a fixed header and footer. --}}
                    <dl class="rg-admin-detail-list">
                        @foreach (range(1, 24) as $month)
                            <div class="rg-admin-detail-row">
                                <x-admin.ui.icon name="image" class="rg-admin-detail-row__icon" />
                                <dt class="rg-admin-detail-row__label">{{ date('F', mktime(0, 0, 0, ($month - 1) % 12 + 1, 1)) }} {{ $month > 12 ? 2026 : 2025 }}</dt>
                                <dd class="rg-admin-detail-row__value">{{ 40 + $month }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
                <x-slot:footer>
                    <span style="flex: 1"></span>
                    <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
                    <x-admin.ui.button variant="primary" x-on:click="drawer = false">Save</x-admin.ui.button>
                </x-slot:footer>
            </x-admin.ui.drawer>
        </template>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FBK-01']])
    <div class="rg-admin-kit__stack" style="align-items: flex-start; gap: 16px">
        <div class="rg-admin-kit__wrap">
            <x-admin.ui.button x-on:click="$dispatch('rg-admin-toast', { message: '3 posts approved and published', tone: 'success' })" data-kit-toast="success">Show success toast</x-admin.ui.button>
            <x-admin.ui.button x-on:click="$dispatch('rg-admin-toast', { message: 'PurgeMediaAsset failed again: asset is still referenced', tone: 'error' })" data-kit-toast="error">Show error toast</x-admin.ui.button>
            <x-admin.ui.button x-on:click="$dispatch('rg-admin-toast', { message: 'Regeneration dispatched for A-209452', tone: 'info' })" data-kit-toast="info">Show info toast</x-admin.ui.button>
        </div>

        <div class="rg-admin-kit__stack" style="align-items: flex-start; gap: 8px">
            @foreach ($toasts as [$icon, $tone, $text, $undo])
                <div class="rg-admin-toast">
                    <x-admin.ui.icon :name="$icon" class="rg-admin-toast__icon--{{ $tone }}" :label="ucfirst($tone)" />
                    <span class="rg-admin-toast__text">{{ $text }}</span>
                    @if ($undo)
                        <button type="button" class="rg-admin-toast__action">Undo</button>
                    @endif
                    <button type="button" class="rg-admin-toast__close" aria-label="Dismiss">
                        <x-admin.ui.icon name="x" :size="14" />
                    </button>
                </div>
            @endforeach
            <span class="rg-admin-kit__caption">static · the three tones, and Undo for a reversible action</span>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FBK-02']])
    <div class="rg-admin-kit__stack" style="max-width: 620px; gap: 8px">
        <x-admin.ui.inline-notice tone="info">
            The target is hidden, but this report stays open until you resolve or ignore it.
        </x-admin.ui.inline-notice>
        <x-admin.ui.inline-notice tone="warning">
            Links to /c/small-pets will stop working after you save.
        </x-admin.ui.inline-notice>
        <div class="rg-admin-kit__stack" style="gap: 4px">
            <x-admin.ui.inline-notice tone="danger">
                This can’t be undone. After finalizing, no moderator or admin can restore this post.
            </x-admin.ui.inline-notice>
            <span class="rg-admin-kit__caption">danger · inside irreversible confirmation dialogs only</span>
        </div>
        <x-admin.ui.inline-notice tone="success">
            Valid. 2 more options can be activated.
        </x-admin.ui.inline-notice>
        <div class="rg-admin-kit__frame rg-admin-kit__frame--clip">
            <x-admin.ui.inline-notice tone="info" icon="sparkles" strip>
                2 AI suggestions not saved yet. Nothing changes for visitors until you save.
                <x-slot:actions>
                    <x-admin.ui.button variant="ghost" size="sm">Discard all</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
            <div style="padding: 12px 16px" class="rg-admin-table__meta">strip · full width under a card toolbar</div>
        </div>
    </div>
@endcomponent
