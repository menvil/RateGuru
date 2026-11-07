@php
    /*
     * Deleting a tag (OVL-01), decided by what carries it now. Unused, it is
     * a danger confirmation naming the tag, since its stored translations go
     * with it. While posts carry it, it cannot be deleted, and the dialog
     * says so with nothing to confirm: removing it from those posts is the
     * way, and merging it into another tag is not available yet.
     */
    $name = '#'.$row['name'];
    $posts = $row['posts'];
    $postsText = number_format($posts).' '.\Illuminate\Support\Str::plural('post', $posts);
@endphp

@if ($posts === 0)
    <x-admin.ui.confirm-dialog
        id="rg-admin-tags-confirm"
        tone="danger"
        icon="trash-2"
        :title="'Delete '.$name.'?'"
        wire:key="confirm-delete-tag-{{ $row['id'] }}"
        x-on:dismiss="$wire.closeConfirmation()"
    >
        <p>No posts use this tag. Its stored translations are deleted with it.</p>

        <x-slot:details>
            <div class="rg-admin-dialog-subject">
                <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="tag" :size="16" /></span>
                <span class="rg-admin-dialog-subject__text">
                    <span class="rg-admin-dialog-subject__title">{{ $row['name'] }}</span>
                    <span class="rg-admin-dialog-subject__meta">{{ $row['slug'] }} · {{ $postsText }}</span>
                </span>
            </div>
        </x-slot:details>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="danger" wire:click="delete({{ $row['id'] }})" wire:loading.attr="disabled">Delete tag</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@else
    <x-admin.ui.confirm-dialog
        id="rg-admin-tags-confirm"
        tone="warning"
        icon="tag"
        :title="$name.' is still in use'"
        wire:key="confirm-blocked-tag-{{ $row['id'] }}"
        x-on:dismiss="$wire.closeConfirmation()"
    >
        <p>It is attached to {{ $postsText }}. Remove it from {{ $posts === 1 ? 'that post' : 'those posts' }} before deleting it.</p>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Close</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@endif
