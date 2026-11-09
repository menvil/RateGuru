@php
    /*
     * A game's Preview (PreviewGame): what to play and as whom down the left,
     * the game itself in a frame — the site's own page, drawn in the public
     * design — on the right. Nothing the frame does is recorded.
     */
@endphp

<div class="rg-admin rg-admin-screen rg-admin-games" x-data>
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-screen__body">
        <div class="rg-admin-games__preview">
            <x-admin.ui.card title="What to play" description="Preview only. Picks, emails and accounts aren’t recorded.">
                <div class="rg-admin-games__form">
                    <label class="rg-admin-field">
                        <span class="rg-admin-label">Source</span>
                        <select class="rg-admin-games__select" wire:model.live="source">
                            <option value="draft">Draft</option>
                            @foreach ($versions as $version)
                                <option value="{{ $version['id'] }}">{{ $version['label'] }} as published</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($source === 'draft')
                        <label class="rg-admin-field">
                            <span class="rg-admin-label">Content set</span>
                            <select class="rg-admin-games__select" wire:model.live="set">
                                @foreach ($sets as $item)
                                    <option value="{{ $item->id }}">{{ $item->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="rg-admin-field">
                            <span class="rg-admin-label">Flow</span>
                            <select class="rg-admin-games__select" wire:model.live="flow">
                                @foreach ($flows as $item)
                                    <option value="{{ $item->id }}">{{ $item->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label class="rg-admin-field">
                        <span class="rg-admin-label">Visitor</span>
                        <select class="rg-admin-games__select" wire:model.live="visitor">
                            <option value="anonymous">Anonymous</option>
                            <option value="known">Anonymous, email known</option>
                            <option value="authenticated">Logged in</option>
                        </select>
                    </label>
                    <label class="rg-admin-field">
                        <span class="rg-admin-label">Language</span>
                        <select class="rg-admin-games__select" wire:model.live="locale">
                            @foreach ($locales as $item)
                                <option value="{{ $item['code'] }}">{{ $item['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="rg-admin-field">
                        <span class="rg-admin-label">Start</span>
                        <select class="rg-admin-games__select" wire:model.live="start">
                            @foreach ($starts as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="rg-admin-games__source" role="group" aria-label="Screen">
                        <x-admin.ui.button size="sm" :variant="$device === 'mobile' ? 'primary' : 'secondary'" wire:click="$set('device', 'mobile')">Mobile</x-admin.ui.button>
                        <x-admin.ui.button size="sm" :variant="$device === 'desktop' ? 'primary' : 'secondary'" wire:click="$set('device', 'desktop')">Desktop</x-admin.ui.button>
                        <x-admin.ui.button size="sm" variant="ghost" icon="external-link" :href="$frameUrl" target="_blank">Open</x-admin.ui.button>
                    </div>
                </div>
            </x-admin.ui.card>

            <div @class(['rg-admin-games__device', 'rg-admin-games__device--desktop' => $device === 'desktop'])>
                <iframe src="{{ $frameUrl }}" title="Game preview" class="rg-admin-games__frame" wire:key="frame-{{ md5($frameUrl) }}" data-testid="game-preview-frame"></iframe>
            </div>
        </div>
    </div>
</div>
