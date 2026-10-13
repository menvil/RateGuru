@php
    /*
     * One option in the drawer (OVL-02): created in English, or edited as it
     * is now. Its key follows the label until one is typed and is fixed once
     * saved (FRM-04): every vote stores it. Active (FRM-06) cannot change
     * while the option is archived or the group's range would refuse it, and
     * a notice says why. Archived at is read-only: Archive sets it.
     */
    $row = $drawer['row'];
    $creating = $row === null;
    $drawerId = 'rg-admin-rating-option-drawer';
    $lock = $drawer['lock'];
@endphp

<x-admin.ui.drawer
    :id="$drawerId"
    :title="$creating ? 'New option in '.$group->label : 'Edit '.$row['label']"
    :subtitle="$creating ? 'English reference. Translate it afterwards.' : number_format($row['votes']).' '.\Illuminate\Support\Str::plural('vote', $row['votes'])"
    wire:key="rating-option-drawer-{{ $creating ? 'new' : $row['id'] }}"
    x-on:dismiss="$wire.closeOptionDrawer()"
>
    <form id="{{ $drawerId }}-form" class="rg-admin-taxonomy__form" wire:submit="saveOption" novalidate>
        @if ($creating && $optionKeyGenerated)
            <x-admin.ui.text-field
                label="Label"
                tag="English · reference"
                id="{{ $drawerId }}-label"
                :value="$draftOptionLabel"
                placeholder="e.g. Couch potato"
                hint="Shown on the vote button."
                :error="$errors->first('draftOptionLabel') ?: null"
                wire:model.live.debounce.400ms="draftOptionLabel"
                autofocus
            />
        @else
            <x-admin.ui.text-field
                label="Label"
                tag="English · reference"
                id="{{ $drawerId }}-label"
                :value="$draftOptionLabel"
                placeholder="e.g. Couch potato"
                hint="Shown on the vote button."
                :error="$errors->first('draftOptionLabel') ?: null"
                wire:model.blur="draftOptionLabel"
            />
        @endif

        @if ($creating)
            <x-admin.ui.locked-identifier
                label="Key"
                id="{{ $drawerId }}-key"
                :value="$draftOptionKey"
                :generated="$optionKeyGenerated ? 'Generated from label' : null"
                hint="Stored with every vote. Lowercase letters, numbers and underscores. Fixed once the option is created."
                :error="$errors->first('draftOptionKey') ?: null"
                maxlength="120"
                wire:model.blur="draftOptionKey"
            />
        @else
            <x-admin.ui.locked-identifier
                label="Key"
                id="{{ $drawerId }}-key"
                :value="$row['key']"
                locked
                locked-note="Fixed after creation"
                hint="Stored with every vote."
            />
        @endif

        <x-admin.ui.textarea
            label="Description"
            id="{{ $drawerId }}-description"
            :value="$draftOptionDescription"
            :limit="\App\Support\Translations\ProjectTranslationCatalog::FIELDS['rating_options']['description'][0]"
            :required="false"
            hint="What a vote for this option means."
            :error="$errors->first('draftOptionDescription') ?: null"
            wire:model="draftOptionDescription"
        />

        <div class="rg-admin-taxonomy__pair">
            <x-admin.ui.number-stepper
                label="Sort order"
                id="{{ $drawerId }}-sort"
                :value="$draftOptionSort"
                :min="0"
                :max="\App\Filament\Resources\RatingGroups\Pages\EditRatingGroup::MAX_SORT_ORDER"
                :error="$errors->first('draftOptionSort') ?: null"
                wire:model="draftOptionSort"
            />
            <x-admin.ui.switch
                label="Active"
                id="{{ $drawerId }}-active"
                :checked="$draftOptionActive"
                on="Shown to voters"
                off="Hidden from voters"
                :disabled="$lock !== null"
                wire:model="draftOptionActive"
            />
        </div>

        @if ($lock !== null)
            <x-admin.ui.inline-notice tone="info">{{ $lock }}</x-admin.ui.inline-notice>
        @endif

        @if ($errors->has('draftOptionActive'))
            <p class="rg-admin-error" id="{{ $drawerId }}-active-error">
                <x-admin.ui.icon name="circle-alert" :size="12" />
                {{ $errors->first('draftOptionActive') }}
            </p>
        @endif

        @unless ($creating)
            <div class="rg-admin-field">
                <span class="rg-admin-label" id="{{ $drawerId }}-archived-label">Archived at</span>
                <div class="rg-admin-input rg-admin-input--disabled" role="note" aria-labelledby="{{ $drawerId }}-archived-label">{{ $row['archived'] ?? 'Not archived' }}</div>
                <p class="rg-admin-hint">Set by Archive. Archived options are hidden from voters; their votes stay in results.</p>
            </div>
        @endunless
    </form>

    <x-slot:footer>
        <span class="rg-admin-taxonomy__spacer"></span>
        <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
        <x-admin.ui.button variant="primary" type="submit" form="{{ $drawerId }}-form" wire:loading.attr="disabled" wire:target="saveOption">
            {{ $creating ? 'Create option' : 'Save option' }}
        </x-admin.ui.button>
    </x-slot:footer>
</x-admin.ui.drawer>
