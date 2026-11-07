@php
    /*
     * One category in the drawer (OVL-02): created in English, or edited as
     * it is now. The slug follows the name until somebody types one (FRM-04),
     * and is locked once saved until Change slug; while it is unlocked a
     * warning says what changing it breaks. Sort order (FRM-05) and Active
     * (FRM-06) apply when the drawer is saved. Delete, on the left, replaces
     * the drawer with its confirmation.
     */
    $row = $drawer['row'];
    $creating = $row === null;
    $drawerId = 'rg-admin-category-drawer';
    $original = $row['slug'] ?? '';

    $slugWarning = match (true) {
        $creating || ! $slugUnlocked => null,
        $draftSlug !== $original => "Feed links filtered by “{$original}” stop working once you save. Bookmarks and shared links to them break.",
        default => 'Changing the slug breaks existing links to this category. Only do this to fix a mistake.',
    };

    $translateReason = match (true) {
        $creating => 'Create the category first.',
        ! $row['active'] => 'Inactive categories are translated once they are active again.',
        default => 'Only administrators translate.',
    };
@endphp

<x-admin.ui.drawer
    :id="$drawerId"
    :title="$creating ? 'New category' : 'Edit category'"
    :subtitle="$creating ? 'Created in English. Translate it afterwards.' : number_format($row['posts']).' '.\Illuminate\Support\Str::plural('post', $row['posts']).' · ID '.$row['id']"
    wire:key="category-drawer-{{ $creating ? 'new' : $row['id'] }}"
    x-on:dismiss="$wire.closeDrawer()"
>
    <form id="{{ $drawerId }}-form" class="rg-admin-taxonomy__form" wire:submit="save" novalidate>
        @if ($creating && $slugGenerated)
            <x-admin.ui.text-field
                label="Name"
                tag="English · reference"
                id="{{ $drawerId }}-name"
                :value="$draftName"
                placeholder="e.g. Guinea pigs"
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
                placeholder="e.g. Guinea pigs"
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
            :hint="$creating ? 'Stable URL identifier. Fixed once the category is saved.' : 'Stable URL identifier used in feed links and filters.'"
            :error="$errors->first('draftSlug') ?: null"
            :warning="$slugWarning"
            maxlength="100"
            wire:model.blur="draftSlug"
        >
            <x-slot:unlock wire:click="unlockSlug">Change slug</x-slot:unlock>
        </x-admin.ui.locked-identifier>

        <div class="rg-admin-taxonomy__pair">
            <x-admin.ui.number-stepper
                label="Sort order"
                id="{{ $drawerId }}-sort"
                :value="$draftSortOrder"
                :min="0"
                :max="\App\Filament\Resources\Categories\Pages\ManageCategories::MAX_SORT_ORDER"
                hint="Lower numbers appear first."
                :error="$errors->first('draftSortOrder') ?: null"
                wire:model="draftSortOrder"
            />
            <x-admin.ui.switch
                label="Active"
                id="{{ $drawerId }}-active"
                :checked="$draftActive"
                on="Shown to visitors"
                off="Hidden from visitors"
                on-hint="Listed in navigation and the upload form."
                off-hint="Existing posts keep it. New posts can’t use it."
                wire:model="draftActive"
            />
        </div>

        @include('filament.pages.taxonomy.languages', [
            'id' => $drawerId,
            'chips' => $drawer['chips'],
            'reference' => $creating ? '' : $row['name'],
            'translate' => $drawer['translate'],
            'translateReason' => $translateReason,
        ])
    </form>

    <x-slot:footer>
        @unless ($creating)
            {{-- The confirmation replaces the drawer. --}}
            <x-admin.ui.button icon="trash-2" x-on:click="hide()" wire:click="askToDelete({{ $row['id'] }})">Delete</x-admin.ui.button>
        @endunless
        <span class="rg-admin-taxonomy__spacer"></span>
        <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
        <x-admin.ui.button variant="primary" type="submit" form="{{ $drawerId }}-form" wire:loading.attr="disabled" wire:target="save">
            {{ $creating ? 'Create category' : 'Save' }}
        </x-admin.ui.button>
    </x-slot:footer>
</x-admin.ui.drawer>
