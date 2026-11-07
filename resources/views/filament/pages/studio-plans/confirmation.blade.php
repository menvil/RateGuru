{{--
    Delete draft, asked first (OVL-01): the draft goes, its photo goes back
    to the media lifecycle — released, its files kept through the grace
    period — and nothing else. Shared by the list and the editor; each
    closes it with closeConfirmation() and deletes with delete().
--}}
<x-admin.ui.confirm-dialog
    id="rg-admin-studio-plan-delete"
    tone="danger"
    icon="trash-2"
    title="Delete this draft?"
    wire:key="confirm-delete-studio-plan-{{ $plan['id'] }}"
    x-on:dismiss="$wire.closeConfirmation()"
>
    <p>The draft will be removed. Its image will be released if nothing else uses it.</p>

    <x-slot:details>
        <div class="rg-admin-dialog-subject">
            <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="newspaper" :size="16" /></span>
            <span class="rg-admin-dialog-subject__text">
                <span class="rg-admin-dialog-subject__title">{{ $plan['title'] }}</span>
                <span class="rg-admin-dialog-subject__meta">#{{ $plan['id'] }} · Draft</span>
            </span>
        </div>
    </x-slot:details>

    <x-slot:actions>
        <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
        {{-- The draft goes with everything in it: nothing left unsaved on the page asks before the page leaves. --}}
        <x-admin.ui.button variant="danger" wire:click="delete" x-on:click="if ($store.adminEditor) $store.adminEditor.leaving = true" wire:loading.attr="disabled">Delete draft</x-admin.ui.button>
    </x-slot:actions>
</x-admin.ui.confirm-dialog>
