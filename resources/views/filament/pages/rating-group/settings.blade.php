@php
    /*
     * The group's settings (LAY-02 card, two columns): the key — made from the
     * label on create, fixed once saved (FRM-04) — the English label and
     * description, the range of active options and the place on post pages
     * (FRM-05), and whether voters see it (FRM-06). Min, Max and Active are
     * sent as they change, so the range is checked against the group's
     * active options before Save.
     */
    $minError = $errors->first('draftMin') ?: ($problems['draftMin'] ?? null);
    $maxError = $errors->first('draftMax') ?: ($problems['draftMax'] ?? null);
@endphp

<x-admin.ui.card title="Group" description="English reference text.">
    <div class="rg-admin-rating-group__fields">
        @if ($creating)
            <x-admin.ui.locked-identifier
                label="Key"
                id="rg-admin-rating-group-key"
                :value="$draftKey"
                :generated="$keyGenerated ? 'Generated from label' : null"
                hint="Stable identifier of the group. Fixed once it is created."
                :error="$errors->first('draftKey') ?: null"
                maxlength="120"
                wire:model.blur="draftKey"
            />
        @else
            <x-admin.ui.locked-identifier
                label="Key"
                id="rg-admin-rating-group-key"
                :value="$group->key"
                locked
                locked-note="Fixed after creation"
                hint="Stable identifier of the group."
            />
        @endif

        @if ($creating && $keyGenerated)
            <x-admin.ui.text-field
                label="Label"
                tag="English · reference"
                id="rg-admin-rating-group-label"
                :value="$draftLabel"
                placeholder="e.g. Real or fake"
                :error="$errors->first('draftLabel') ?: null"
                maxlength="{{ $labelLimit }}"
                wire:model.live.debounce.400ms="draftLabel"
            />
        @else
            <x-admin.ui.text-field
                label="Label"
                tag="English · reference"
                id="rg-admin-rating-group-label"
                :value="$draftLabel"
                placeholder="e.g. Real or fake"
                :error="$errors->first('draftLabel') ?: null"
                maxlength="{{ $labelLimit }}"
                wire:model="draftLabel"
            />
        @endif

        <x-admin.ui.textarea
            class="rg-admin-rating-group__wide"
            label="Description"
            id="rg-admin-rating-group-description"
            :value="$draftDescription"
            :limit="$descriptionLimit"
            :required="false"
            hint="What the group asks voters about."
            :error="$errors->first('draftDescription') ?: null"
            wire:model="draftDescription"
        />

        <x-admin.ui.number-stepper
            label="Minimum active options"
            id="rg-admin-rating-group-min"
            :value="$draftMin"
            :min="\App\Models\RatingGroup::FEWEST_OPTIONS"
            :max="\App\Models\RatingGroup::MOST_OPTIONS"
            hint="Voters always see at least this many."
            :error="$minError"
            wire:model.live.debounce.400ms="draftMin"
        />

        <x-admin.ui.number-stepper
            label="Maximum active options"
            id="rg-admin-rating-group-max"
            :value="$draftMax"
            :min="\App\Models\RatingGroup::FEWEST_OPTIONS"
            :max="\App\Models\RatingGroup::MOST_OPTIONS"
            hint="Keeps the vote form short."
            :error="$maxError"
            wire:model.live.debounce.400ms="draftMax"
        />

        <x-admin.ui.number-stepper
            label="Sort order"
            id="rg-admin-rating-group-sort"
            :value="$draftSort"
            :min="0"
            :max="\App\Filament\Resources\RatingGroups\Pages\EditRatingGroup::MAX_SORT_ORDER"
            hint="Position on the post page."
            :error="$errors->first('draftSort') ?: null"
            wire:model="draftSort"
        />

        @if ($creating)
            <x-admin.ui.switch
                label="Active"
                id="rg-admin-rating-group-active"
                :checked="false"
                on="Shown to voters"
                off="Hidden from voters"
                off-hint="A new group starts inactive. Add its options, then activate it."
                disabled
            />
        @else
            <x-admin.ui.switch
                label="Active"
                id="rg-admin-rating-group-active"
                :checked="$draftActive"
                on="Shown to voters"
                off="Hidden from voters"
                on-hint="Turning it off keeps all votes."
                off-hint="Activate once the options are ready."
                wire:model.live="draftActive"
            />
        @endif
    </div>
</x-admin.ui.card>
