{{--
    A game's status change, asked first (OVL-01): what it does to visitors and
    to the game's numbers. Shared by the library and the editor; each closes
    it with closeStatus() and changes the status with changeStatus(). Every
    change can be taken back, so the confirm is never the danger button.
--}}
<x-admin.ui.confirm-dialog
    id="rg-admin-game-status"
    :tone="$confirmation['tone']"
    :icon="$confirmation['icon']"
    :title="$confirmation['title']"
    x-on:dismiss="$wire.closeStatus()"
>
    <p>{{ $confirmation['body'] }}</p>

    <x-slot:details>
        <div class="rg-admin-dialog-subject">
            <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="gamepad-2" :size="16" /></span>
            <span class="rg-admin-dialog-subject__text">
                <span class="rg-admin-dialog-subject__title">{{ $confirmation['subject'] }}</span>
            </span>
        </div>
    </x-slot:details>

    <x-slot:actions>
        <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
        <x-admin.ui.button variant="primary" wire:click="changeStatus" wire:loading.attr="disabled">{{ $confirmation['button'] }}</x-admin.ui.button>
    </x-slot:actions>
</x-admin.ui.confirm-dialog>
