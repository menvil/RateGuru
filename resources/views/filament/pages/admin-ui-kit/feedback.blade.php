@php
    // [icon, tone, text, undo]
    $toasts = [
        ['check', 'success', '3 posts approved and published', true],
        ['circle-alert', 'error', 'PurgeMediaAsset failed again: asset is still referenced', false],
        ['info', 'info', 'Regeneration dispatched for A-209452 · 2 variants', false],
    ];
@endphp

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FBK-01']])
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
        <x-admin.ui.inline-notice tone="danger">
            This can’t be undone. After finalizing, no moderator or admin can restore this post.
        </x-admin.ui.inline-notice>
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
