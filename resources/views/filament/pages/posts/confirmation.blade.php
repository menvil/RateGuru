@php
    /*
     * The moderation confirmations (OVL-01), at the friction each asks for:
     * Reject is light — the reason is optional; Hide is firm — the reason is
     * required, for one post or for every published one selected; Finalize
     * removal is irreversible — the red callout, what it does, a required
     * internal reason and an acknowledgement. The reason chips are the
     * reasons visitors report with. Each says where it is recorded.
     */
    use App\Filament\Resources\Posts\Pages\ListPosts;

    $action = $confirmation['action'];
    $posts = $confirmation['posts'];
    $one = count($posts) === 1;
    $moderator = auth()->user()?->name;

    [$tone, $icon, $title, $confirm] = match ($action) {
        'reject' => ['default', 'circle-x', 'Reject this post?', 'Reject post'],
        'hide' => ['warning', 'eye-off', $one ? 'Hide this post?' : 'Hide '.count($posts).' posts?', $one ? 'Hide post' : 'Hide '.count($posts).' posts'],
        default => ['danger', 'triangle-alert', 'Finalize removal?', 'Finalize removal'],
    };
    $required = $action !== 'reject';
    // The confirm button waits for what the server will ask for anyway, as it is typed.
    $waiting = match ($action) {
        'reject' => 'false',
        'hide' => "(\$wire.reason ?? '').trim().length < 3",
        default => "(\$wire.reason ?? '').trim().length < 3 || ! \$wire.acknowledged",
    };
@endphp

<x-admin.ui.confirm-dialog
    id="rg-admin-posts-confirm"
    :tone="$tone"
    :icon="$icon"
    :title="$title"
    wire:key="confirm-{{ $action }}-{{ implode('-', array_column($posts, 'id')) }}"
    x-on:dismiss="$wire.closeConfirmation()"
>
    @if ($action === 'reject')
        <p>It won’t be published. The reason, if you give one, is kept with the decision.</p>
    @elseif ($action === 'hide')
        <p>{{ $one ? 'The post is' : 'The posts are' }} removed from feeds, search and {{ $one ? 'its page' : 'their pages' }}. Restore stays available until the removal is finalized.</p>
    @else
        <p>Finalizing is the last moderation step for this post. It is meant for content that must never come back.</p>
    @endif

    <x-slot:details>
        <div class="rg-admin-dialog-subject">
            <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon :name="$one ? 'image' : 'images'" :size="16" /></span>
            <span class="rg-admin-dialog-subject__text">
                <span class="rg-admin-dialog-subject__title">{{ $one ? $posts[0]['title'] : implode(', ', array_column($posts, 'title')) }}</span>
                <span class="rg-admin-dialog-subject__meta">{{ $one ? $posts[0]['meta'] : implode(' · ', array_map(fn (array $post): string => '#'.$post['id'], $posts)) }}</span>
            </span>
        </div>

        @if ($action === 'finalize')
            <x-admin.ui.inline-notice tone="danger">This can’t be undone. After finalizing, no moderator or administrator can restore this post.</x-admin.ui.inline-notice>

            <div>
                <div class="rg-admin-section-label">
                    <h3 class="rg-admin-section-label__text">What happens</h3>
                </div>
                <ul class="rg-admin-dialog-facts">
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="eye-off" :size="16" />The author and visitors lose access permanently.</li>
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="lock" :size="16" />Restore is disabled for everyone, administrators included.</li>
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="archive" :size="16" />An internal copy is kept under the moderation retention rules, then deleted.</li>
                </ul>
            </div>
        @endif

        <x-admin.ui.textarea
            :label="$action === 'finalize' ? 'Internal reason' : 'Moderation reason'"
            id="rg-admin-posts-reason"
            :value="$reason"
            :required="$required"
            :limit="ListPosts::REASON_LIMIT"
            :presets="$action === 'finalize' ? [] : $reasons"
            :placeholder="$required ? 'Describe why this is necessary…' : 'Add a note for the moderation log…'"
            :hint="$action === 'finalize' ? 'Visible to administrators only. Not shared with the author.' : 'Stored in the moderation log.'"
            :error="$errors->first('reason') ?: null"
            wire:model="reason"
            :autofocus="$required"
        />

        @if ($action === 'finalize')
            <div>
                <x-admin.ui.checkbox label="I understand this post can no longer be restored." id="rg-admin-posts-acknowledge" wire:model="acknowledged" :aria-invalid="$errors->has('acknowledged') ? 'true' : null" />
                @error('acknowledged')
                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                @enderror
            </div>
        @endif
    </x-slot:details>

    <x-slot:footnote>{{ $moderator !== null ? 'Recorded in the moderation log as '.$moderator.'.' : 'Recorded in the moderation log.' }}</x-slot:footnote>

    <x-slot:actions>
        <x-admin.ui.button x-on:click="dismiss()" :autofocus="! $required">Cancel</x-admin.ui.button>
        <x-admin.ui.button
            :variant="$action === 'finalize' ? 'danger' : 'primary'"
            wire:click="confirm"
            wire:loading.attr="disabled"
            x-bind:disabled="{{ $waiting }}"
        >{{ $confirm }}</x-admin.ui.button>
    </x-slot:actions>
</x-admin.ui.confirm-dialog>
