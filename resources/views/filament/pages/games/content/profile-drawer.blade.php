{{--
    Edit profile (OVL-02): its name, the matches it stands for, what it says,
    its badge, its share text and share image. Changes are part of the set's
    draft, saved with the set; what a save refused shows at its field, and a
    share image refused as it is chosen under the upload.
--}}
@php($path = 'profiles.'.$editingIndex)
<x-admin.ui.drawer id="rg-admin-game-profile" title="Edit profile" :subtitle="$editing['name']" x-on:dismiss="$wire.closeProfile()">
    <div class="rg-admin-games__form">
        <x-admin.ui.text-field label="Name" id="rg-admin-profile-name" :value="$editing['name']" :error="$errors->first($path.'.name') ?: null" wire:model.live.debounce.400ms="{{ $path }}.name" />
        <div class="rg-admin-games__pair">
            <x-admin.ui.number-stepper label="From (matches)" id="rg-admin-profile-min" :value="$editing['min']" :min="0" :max="$checks->scoredRounds" :error="$errors->first($path.'.min') ?: null" wire:model.live="{{ $path }}.min" />
            <x-admin.ui.number-stepper label="To (matches)" id="rg-admin-profile-max" :value="$editing['max']" :min="0" :max="$checks->scoredRounds" :error="$errors->first($path.'.max') ?: null" wire:model.live="{{ $path }}.max" />
        </div>
        <x-admin.ui.textarea label="Description" id="rg-admin-profile-description" :value="$editing['description']" :rows="2" :limit="$limits['profileDescription']" :required="false" :error="$errors->first($path.'.description') ?: null" wire:model.live.debounce.400ms="{{ $path }}.description" />
        <fieldset class="rg-admin-games__fieldset">
            <legend class="rg-admin-label">Badge</legend>
            <div class="rg-admin-games__icons">
                @foreach ($icons as $icon)
                    <label class="rg-admin-games__icon-choice">
                        <input type="radio" class="rg-admin-sr-only" value="{{ $icon }}" wire:model.live="{{ $path }}.icon" />
                        <span @class(['rg-admin-games__icon-tile', 'rg-admin-games__icon-tile--current' => $editing['icon'] === $icon])><x-admin.ui.icon :name="$icon" :size="16" :label="$icon" /></span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <x-admin.ui.textarea label="Share text" id="rg-admin-profile-share" :value="$editing['share_text']" :rows="2" :limit="$limits['shareText']" :required="false" :error="$errors->first($path.'.share_text') ?: null" hint="{profile} and {game} become the player’s profile and the game’s title. Keep it under 140 characters for X." wire:model.live.debounce.400ms="{{ $path }}.share_text" />
        <div class="rg-admin-field">
            <span class="rg-admin-label">Share image · optional</span>
            <p class="rg-admin-games__note">1200 × 630. Without one, the game’s cover is used.</p>
            <div class="rg-admin-games__set-tools">
                <label class="rg-admin-button rg-admin-button--secondary rg-admin-button--sm" for="rg-admin-profile-share-image">
                    <x-admin.ui.icon name="upload" :size="14" />
                    Upload share image
                </label>
                <input id="rg-admin-profile-share-image" type="file" accept="image/jpeg,image/png,image/webp" class="rg-admin-sr-only" wire:model="uploads.profile:{{ $editing['key'] }}" />
                @if (! empty($editing['share_asset_id']) || isset($uploads['profile:'.$editing['key']]))
                    <x-admin.ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeShareImage('{{ $editing['key'] }}')">Remove</x-admin.ui.button>
                @endif
            </div>
            @error('uploads.profile:'.$editing['key'])
                <p class="rg-admin-error" role="alert"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
            @enderror
        </div>
    </div>

    <x-slot:footer>
        <x-admin.ui.button variant="ghost" icon="trash-2" wire:click="removeProfile('{{ $editing['key'] }}')">Delete profile</x-admin.ui.button>
        <x-admin.ui.button variant="primary" x-on:click="dismiss()">Done</x-admin.ui.button>
    </x-slot:footer>
</x-admin.ui.drawer>
