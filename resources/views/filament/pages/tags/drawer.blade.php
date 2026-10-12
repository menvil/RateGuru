@php
    /*
     * One tag in the drawer (OVL-02): created in English, or edited as it is
     * now. The slug follows the name until somebody types one (FRM-04); left
     * blank it is made from the name when saved. Once saved it is locked
     * until Change slug, and while it is unlocked a warning says what
     * changing it breaks. Delete, on the left, replaces the drawer with its
     * confirmation and is an administrator's.
     */
    $row = $drawer['row'];
    $creating = $row === null;
    $drawerId = 'rg-admin-tag-drawer';
    $original = $row['slug'] ?? '';

    $slugWarning = match (true) {
        $creating || ! $slugUnlocked => null,
        $draftSlug !== $original => "Feed links filtered by “{$original}” stop working once you save. Bookmarks and shared links to them break.",
        default => 'Changing the slug breaks existing links to this tag. Only do this to fix a mistake.',
    };
@endphp

<x-admin.ui.drawer
    :id="$drawerId"
    :title="$creating ? 'New tag' : 'Edit tag'"
    :subtitle="$creating ? 'Created in English. Translate it afterwards.' : number_format($row['posts']).' '.\Illuminate\Support\Str::plural('post', $row['posts']).' · ID '.$row['id']"
    wire:key="tag-drawer-{{ $creating ? 'new' : $row['id'] }}"
    x-on:dismiss="$wire.closeDrawer()"
>
    <form id="{{ $drawerId }}-form" class="rg-admin-taxonomy__form" wire:submit="save" novalidate>
        @if ($creating && $slugGenerated)
            <x-admin.ui.text-field
                label="Name"
                tag="English · reference"
                id="{{ $drawerId }}-name"
                :value="$draftName"
                placeholder="e.g. sunbathing"
                :error="$errors->first('draftName') ?: null"
                maxlength="{{ $nameLimit }}"
                wire:model.live.debounce.400ms="draftName"
                autofocus
            />
        @else
            <x-admin.ui.text-field
                label="Name"
                tag="English · reference"
                id="{{ $drawerId }}-name"
                :value="$draftName"
                placeholder="e.g. sunbathing"
                :error="$errors->first('draftName') ?: null"
                maxlength="{{ $nameLimit }}"
                wire:model.blur="draftName"
            />
        @endif

        <x-admin.ui.locked-identifier
            label="Slug"
            id="{{ $drawerId }}-slug"
            :value="$draftSlug"
            :locked="! $creating && ! $slugUnlocked"
            :generated="$creating && $slugGenerated ? 'Generated from name' : null"
            :hint="$creating ? 'Stable URL identifier. Left blank, it is made from the name. Fixed once the tag is saved.' : 'Stable URL identifier used in feed links and filters.'"
            :error="$errors->first('draftSlug') ?: null"
            :warning="$slugWarning"
            maxlength="100"
            wire:model.blur="draftSlug"
        >
            <x-slot:unlock wire:click="unlockSlug">Change slug</x-slot:unlock>
        </x-admin.ui.locked-identifier>

        @include('filament.pages.taxonomy.languages', [
            'id' => $drawerId,
            'chips' => $drawer['chips'],
            'reference' => $creating ? '' : $row['name'],
            'translate' => $drawer['translate'],
            'translateReason' => $creating ? 'Create the tag first.' : 'Only administrators translate.',
        ])
    </form>

    <x-slot:footer>
        @unless ($creating)
            @if ($canDelete)
                {{-- The confirmation replaces the drawer. --}}
                <x-admin.ui.button icon="trash-2" x-on:click="hide()" wire:click="askToDelete({{ $row['id'] }})">Delete</x-admin.ui.button>
            @else
                <x-admin.ui.button icon="trash-2" disabled title="Only administrators delete tags">Delete</x-admin.ui.button>
            @endif
        @endunless
        <span class="rg-admin-taxonomy__spacer"></span>
        <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
        <x-admin.ui.button variant="primary" type="submit" form="{{ $drawerId }}-form" wire:loading.attr="disabled" wire:target="save">
            {{ $creating ? 'Create tag' : 'Save' }}
        </x-admin.ui.button>
    </x-slot:footer>
</x-admin.ui.drawer>
