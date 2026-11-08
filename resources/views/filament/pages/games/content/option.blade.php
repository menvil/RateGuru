{{--
    One option of the round: its picture — a published post's, chosen in the
    post picker, or one uploaded here — its label, and for a round decided by
    a correct answer, whether it is the one.
--}}
@php($optionPath = $path.'.options.'.$option['slot'])
<x-admin.ui.card :title="$option['name']" wire:key="option-{{ $round['key'] }}-{{ $option['slot'] }}">
    <x-slot:aside>
        @if ($option['objective'])
            @if ($option['isCorrect'])
                <x-admin.ui.badge tone="success">Correct answer</x-admin.ui.badge>
            @else
                <x-admin.ui.button size="sm" variant="ghost" wire:click="markCorrect({{ $index }}, {{ $option['slot'] }})">Mark correct</x-admin.ui.button>
            @endif
        @elseif ($option['answer'])
            <x-admin.ui.badge tone="success">Crowd’s pick</x-admin.ui.badge>
        @endif
    </x-slot:aside>

    <div class="rg-admin-games__option">
        <div class="rg-admin-games__option-image">
            @if ($option['preview'] !== null)
                <img src="{{ $option['preview'] }}" alt="{{ $option['name'] }} picture" />
            @else
                <x-admin.ui.icon name="image" :size="20" :label="$option['name'].' has no picture yet'" />
            @endif
        </div>

        <div class="rg-admin-games__option-body">
            <div class="rg-admin-games__source" role="group" aria-label="{{ $option['name'] }} picture from">
                <x-admin.ui.button size="sm" :variant="$option['source'] === 'post' ? 'primary' : 'secondary'" aria-pressed="{{ $option['source'] === 'post' ? 'true' : 'false' }}" wire:click="setSource({{ $index }}, {{ $option['slot'] }}, 'post')">Post</x-admin.ui.button>
                <x-admin.ui.button size="sm" :variant="$option['source'] === 'upload' ? 'primary' : 'secondary'" aria-pressed="{{ $option['source'] === 'upload' ? 'true' : 'false' }}" wire:click="setSource({{ $index }}, {{ $option['slot'] }}, 'upload')">Upload photo</x-admin.ui.button>
            </div>

            @if ($option['source'] === 'post')
                @if ($option['post'] !== null)
                    <p class="rg-admin-games__option-meta">
                        <span class="rg-admin-table__name">{{ $option['post']['title'] !== '' ? $option['post']['title'] : 'Post #'.$option['post']['id'] }}</span>
                        <span class="rg-admin-games__note"><span class="rg-admin-mono">#{{ $option['post']['id'] }}</span>@if ($option['post']['author']) · {{ '@'.$option['post']['author'] }}@endif · {{ $option['post']['share'] !== null ? $option['post']['share'].'% upvotes' : 'no votes yet' }} · {{ number_format($option['post']['votes']) }} site votes</span>
                    </p>
                @endif
                <x-admin.ui.button size="sm" icon="search" wire:click="openPicker({{ $index }}, {{ $option['slot'] }})">{{ $option['post'] !== null ? 'Change post' : 'Choose post' }}</x-admin.ui.button>
            @else
                <div class="rg-admin-games__set-tools">
                    <label class="rg-admin-button rg-admin-button--secondary rg-admin-button--sm" for="rg-admin-upload-{{ $round['key'] }}-{{ $option['slot'] }}">
                        <x-admin.ui.icon name="upload" :size="14" />
                        {{ $option['preview'] !== null ? 'Change image' : 'Upload image' }}
                    </label>
                    <input id="rg-admin-upload-{{ $round['key'] }}-{{ $option['slot'] }}" type="file" accept="image/jpeg,image/png,image/webp" class="rg-admin-sr-only" wire:model="uploads.round:{{ $round['key'] }}:{{ $option['slot'] }}" />
                    @if ($option['preview'] !== null)
                        <x-admin.ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeImage({{ $index }}, {{ $option['slot'] }})">Remove</x-admin.ui.button>
                    @endif
                </div>
                @if ($option['pending'])
                    <p class="rg-admin-games__note">Chosen — stored with the next Save.</p>
                @endif
                @error('uploads.round:'.$round['key'].':'.$option['slot'])
                    <p class="rg-admin-field__error" role="alert">{{ $message }}</p>
                @enderror
            @endif

            <x-admin.ui.text-field label="Answer label" :required="false" :id="'rg-admin-option-label-'.$option['slot']" :value="$option['label']" :error="$errors->first($optionPath.'.label') ?: null" hint="Shown on the picture. Empty shows only the picture; the question explains the choice." wire:model.live.debounce.400ms="{{ $optionPath }}.label" />
        </div>
    </div>
</x-admin.ui.card>
