{{--
    The group editor saves from the top bar (NAV-02): Unsaved group changes
    while the settings differ from what is saved, Discard to put them back,
    and Save — which also waits while the range cannot hold the group's
    active options. The page keeps the Alpine store this reads in step, and
    listens for the events these buttons dispatch.
--}}
<div class="rg-admin-topbar__editor" x-data>
    <span class="rg-admin-topbar__unsaved" x-cloak x-show="$store.ratingGroupEditor?.dirty">Unsaved group changes</span>
    <x-admin.ui.button disabled x-bind:disabled="! $store.ratingGroupEditor?.dirty" x-on:click="$dispatch('discard-rating-group')">Discard</x-admin.ui.button>
    <x-admin.ui.button variant="primary" disabled x-bind:disabled="! $store.ratingGroupEditor?.dirty || $store.ratingGroupEditor?.blocked" x-on:click="$dispatch('save-rating-group')">{{ $save }}</x-admin.ui.button>
</div>
