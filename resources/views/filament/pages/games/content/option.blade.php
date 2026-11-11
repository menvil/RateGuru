{{--
    One option of the round: its picture as a tile — a published post's,
    chosen in the post picker, or one uploaded here, the tile opening either —
    where the picture comes from, its label, and for a round decided by a
    correct answer, whether it is the one.
--}}
@php
    $optionPath = $path.'.options.'.$option['slot'];
    $key = chr(65 + (int) $option['slot']);
    $uploadId = 'rg-admin-upload-'.$round['key'].'-'.$option['slot'];
    $post = $option['post'];
@endphp
<x-admin.ui.card class="rg-admin-games__option-card" :flush="true" wire:key="option-{{ $round['key'] }}-{{ $option['slot'] }}">
    <div class="rg-admin-games__option-head">
        <span class="rg-admin-games__option-key">{{ $key }}</span>
        <span class="rg-admin-games__option-title">{{ $option['name'] }}</span>
        @if ($option['objective'])
            @if ($option['isCorrect'])
                <x-admin.ui.badge tone="success">Correct answer</x-admin.ui.badge>
            @else
                <x-admin.ui.button size="sm" icon="check" wire:click="markCorrect({{ $index }}, {{ $option['slot'] }})">Mark correct</x-admin.ui.button>
            @endif
        @elseif ($option['answer'])
            <x-admin.ui.badge tone="success">Crowd’s pick</x-admin.ui.badge>
        @endif
    </div>

    <div class="rg-admin-games__option">
        @if ($option['source'] === 'post')
            <button type="button" class="rg-admin-games__option-tile" wire:click="openPicker({{ $index }}, {{ $option['slot'] }})" aria-label="{{ $post !== null ? 'Change the post for '.$option['name'] : 'Choose a post for '.$option['name'] }}">
                @if ($option['preview'] !== null)
                    <img src="{{ $option['preview'] }}" alt="" />
                @else
                    <x-admin.ui.icon name="newspaper" :size="28" />
                    <span class="rg-admin-games__option-tile-name">Choose a post</span>
                    <span>A published post with a picture</span>
                @endif
            </button>
        @else
            <label class="rg-admin-games__option-tile" for="{{ $uploadId }}">
                @if ($option['preview'] !== null)
                    <img src="{{ $option['preview'] }}" alt="" />
                @else
                    <x-admin.ui.icon name="image-up" :size="28" />
                    <span class="rg-admin-games__option-tile-name">Upload a picture</span>
                    <span>JPG, PNG or WebP</span>
                @endif
            </label>
        @endif

        {{-- Keyed by the source too: drawn afresh when the server changes it — Discard, say — so the switch shows it. --}}
        <div wire:key="source-{{ $round['key'] }}-{{ $option['slot'] }}-{{ $option['source'] }}" x-data="{ source: @js($option['source']) }" x-init="$watch('source', (value) => $wire.setSource({{ $index }}, {{ $option['slot'] }}, value))">
            <x-admin.ui.segmented :label="$option['name'].' picture from'" :options="['post' => 'Post', 'upload' => 'Upload']" :value="$option['source']" x-model="source" compact class="rg-admin-games__option-source" />
        </div>

        @if ($option['source'] === 'post')
            <p class="rg-admin-games__option-meta">
                @if ($post !== null)
                    <span class="rg-admin-games__option-post">{{ $post['title'] !== '' ? $post['title'] : 'Post #'.$post['id'] }}</span>
                    <span class="rg-admin-games__note"><span class="rg-admin-mono">#{{ $post['id'] }}</span>@if ($post['author']) · {{ '@'.$post['author'] }}@endif · {{ $post['share'] !== null ? $post['share'].'% upvotes' : 'no votes yet' }} · {{ number_format($post['votes']) }} site votes</span>
                @else
                    <span class="rg-admin-games__note">Posts carry their site votes, so the crowd’s pick is known before the game goes live.</span>
                @endif
            </p>
            <div class="rg-admin-games__set-tools">
                <x-admin.ui.button size="sm" icon="search" wire:click="openPicker({{ $index }}, {{ $option['slot'] }})">{{ $post !== null ? 'Change post' : 'Choose post' }}</x-admin.ui.button>
            </div>
        @else
            <p class="rg-admin-games__option-meta">
                <span class="rg-admin-games__note">{{ $option['pending'] ? 'Chosen — stored with the next Save.' : 'An uploaded picture has no site votes: mark the correct option.' }}</span>
            </p>
            <div class="rg-admin-games__set-tools">
                <label class="rg-admin-button rg-admin-button--secondary rg-admin-button--sm" for="{{ $uploadId }}">
                    <x-admin.ui.icon name="upload" :size="14" />
                    {{ $option['preview'] !== null ? 'Change image' : 'Upload image' }}
                </label>
                <input id="{{ $uploadId }}" type="file" accept="image/jpeg,image/png,image/webp" class="rg-admin-sr-only" wire:model="uploads.round:{{ $round['key'] }}:{{ $option['slot'] }}" />
                @if ($option['preview'] !== null)
                    <x-admin.ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeImage({{ $index }}, {{ $option['slot'] }})">Remove</x-admin.ui.button>
                @endif
            </div>
            @error('uploads.round:'.$round['key'].':'.$option['slot'])
                <p class="rg-admin-error" role="alert"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
            @enderror
        @endif

        <x-admin.ui.text-field label="Answer label" :required="false" :id="'rg-admin-option-label-'.$option['slot']" :value="$option['label']" :error="$errors->first($optionPath.'.label') ?: null" hint="Shown on the picture. Empty shows only the picture; the question explains the choice." wire:model.live.debounce.400ms="{{ $optionPath }}.label" />
    </div>
</x-admin.ui.card>
