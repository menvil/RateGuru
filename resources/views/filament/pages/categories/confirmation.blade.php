@php
    /*
     * Deleting a category (OVL-01), decided by what holds it now. Unused, it
     * is a danger confirmation naming the category, since its stored
     * translations go with it. While any post holds it — a deleted one that
     * is kept included, which the database still counts — it cannot be
     * deleted: an active category is offered Deactivate instead, which hides
     * it and keeps its posts; an inactive one has nothing left to offer.
     */
    $name = $row['name'];
    $posts = $row['posts'];
    $postsText = number_format($posts).' '.\Illuminate\Support\Str::plural('post', $posts);
@endphp

@if (! $row['retained'])
    <x-admin.ui.confirm-dialog
        id="rg-admin-categories-confirm"
        tone="danger"
        icon="trash-2"
        :title="'Delete '.$name.'?'"
        wire:key="confirm-delete-category-{{ $row['id'] }}"
        x-on:dismiss="$wire.closeConfirmation()"
    >
        <p>No posts use this category. Its stored translations are deleted with it.</p>

        <x-slot:details>
            <div class="rg-admin-dialog-subject">
                <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="folder" :size="16" /></span>
                <span class="rg-admin-dialog-subject__text">
                    <span class="rg-admin-dialog-subject__title">{{ $name }}</span>
                    <span class="rg-admin-dialog-subject__meta">{{ $row['slug'] }} · {{ $postsText }}</span>
                </span>
            </div>
        </x-slot:details>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="danger" wire:click="delete({{ $row['id'] }})" wire:loading.attr="disabled">Delete category</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@else
    <x-admin.ui.confirm-dialog
        id="rg-admin-categories-confirm"
        tone="warning"
        icon="folder-x"
        :title="$name.' can’t be deleted'"
        wire:key="confirm-blocked-category-{{ $row['id'] }}"
        x-on:dismiss="$wire.closeConfirmation()"
    >
        @if ($posts > 0)
            <p>{{ $postsText }} {{ $posts === 1 ? 'is' : 'are' }} filed under {{ $name }}. Deleting it would leave them without a category.</p>
        @else
            <p>Posts deleted by their authors and still kept are filed under {{ $name }}. Deleting it would leave them without a category.</p>
        @endif
        @unless ($row['active'])
            <p>It is already inactive: hidden from navigation and the upload form.</p>
        @endunless

        @if ($row['active'])
            <x-slot:details>
                <div>
                    <div class="rg-admin-section-label">
                        <h3 class="rg-admin-section-label__text">Deactivate instead</h3>
                    </div>
                    <ul class="rg-admin-dialog-facts">
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="eye-off" :size="16" />Deactivating removes it from navigation and the upload form.</li>
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="image" :size="16" />Existing posts keep the category and stay visible.</li>
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="rotate-ccw" :size="16" />You can activate it again at any time.</li>
                    </ul>
                </div>
            </x-slot:details>
        @endif

        <x-slot:actions>
            @if ($row['active'])
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="primary" wire:click="deactivate({{ $row['id'] }})" wire:loading.attr="disabled">Deactivate {{ $name }}</x-admin.ui.button>
            @else
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Close</x-admin.ui.button>
            @endif
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@endif
