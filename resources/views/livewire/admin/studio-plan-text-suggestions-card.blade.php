{{--
    AI text suggestions on the Post step (StudioPlanTextSuggestionsCard), as
    three cards side by side until they no longer fit. Context: the photo,
    what its analysis says, whose voice the texts are written in — the saved
    author's — the tone of the next batch and Generate suggestions — then
    Generate 10 more, or Try again; with another author chosen and not saved
    yet, Generate saves the draft first. Then the newest batch written from
    the photo, analysis and author's voice the plan has now: its titles in
    one card, its descriptions in another. A new batch takes the place of the one
    before — while it is on its way both lists wait for it, and a failure is
    said in their place. Choosing a title or a description puts it in the
    draft's final field, unsaved, marked here as in use; nothing is generated
    or saved by itself. Read again every few seconds while a batch, or the
    analysis it waits for, is on its way, and not at all otherwise. Every
    text is drawn escaped.
--}}
@php
    $poll = \App\Livewire\Admin\StudioPlanTextSuggestionsCard::POLL_SECONDS;
    $busy = $phase === 'queued' || $phase === 'running';
    $failed = $phase === 'failed' || $phase === 'stale';
    // The lists below were written in another tone than the one now chosen.
    $otherTone = $batch !== null && $batch['tone'] !== $chosenTone;
    $label = match (true) {
        $failed => 'Try again',
        $otherTone => "Generate {$count} in {$chosenTone->label()}",
        $batch !== null => "Generate {$count} more",
        default => 'Generate suggestions',
    };
    $lists = match (true) {
        ! in_array($state, ['ready', 'analysis_pending'], true) => 'empty',
        $busy => 'loading',
        $failed => 'failed',
        $batch !== null => 'ready',
        default => 'empty',
    };
    $meta = match ($lists) {
        'loading' => $phase === 'queued' ? 'Waiting for text suggestions…' : 'Generating suggestions…',
        'failed' => 'Generation failed',
        'ready' => "Set {$batch['set']} · written in {$batch['tone']->label()}",
        default => 'Nothing generated yet',
    };
@endphp

<div
    class="rg-admin-studio__texts"
    data-suggestions="{{ $phase ?? $state }}"
    x-data="{
        get editorAuthor() { return String(this.$wire.$parent?.authorUserId ?? this.$wire.savedAuthorId) },
        get authorChanged() { return this.editorAuthor !== this.$wire.savedAuthorId },
        ask() { this.authorChanged ? this.$dispatch('save-studio-plan', { then: 'suggest' }) : this.$wire.generate(this.editorAuthor) },
    }"
    @if ($polling) wire:poll.{{ $poll }}s.visible @endif
>
    <x-admin.ui.card flush title="Context" class="rg-admin-studio__context">
        <div class="rg-admin-card__body rg-admin-studio__context-body">
            <figure class="rg-admin-studio__context-photo">
                @if ($photo !== null)
                    <img src="{{ $photo }}" alt="The post’s photo" class="rg-admin-studio__context-image" />
                @else
                    <span class="rg-admin-studio__preview-empty"><x-admin.ui.icon name="image" :size="32" />No photo</span>
                @endif
            </figure>

            <div class="rg-admin-studio__context-part">
                <span class="rg-admin-overline">Photo analysis</span>
                @if ($summary !== null)
                    <p class="rg-admin-studio__context-analysis">{{ $summary }}</p>
                @elseif ($state === 'gone')
                    <p class="rg-admin-studio__empty">This draft was deleted.</p>
                @elseif ($state === 'no_image')
                    <x-admin.ui.inline-notice tone="info">Add a photo on the Image step first: suggestions are written from its analysis.</x-admin.ui.inline-notice>
                @elseif ($state === 'changed')
                    <x-admin.ui.inline-notice tone="warning">
                        The photo was changed after this draft was opened. Reload the draft to write texts for the photo it has now.
                        <x-slot:actions>
                            <x-admin.ui.button size="sm" icon="refresh-cw" x-on:click="$dispatch('reload-studio-plan')">Reload</x-admin.ui.button>
                        </x-slot:actions>
                    </x-admin.ui.inline-notice>
                @else
                    <x-admin.ui.inline-notice tone="info" icon="clock" role="status">Text suggestions will be available when photo analysis is complete.</x-admin.ui.inline-notice>
                @endif
            </div>

            @if ($state === 'ready')
                <div class="rg-admin-studio__context-part" data-testid="studio-suggestions-voice">
                    <span class="rg-admin-overline">Author’s voice</span>
                    @if ($voice !== null)
                        <p class="rg-admin-studio__context-analysis">{{ $voice['name'].($voice['about'] !== null ? ' — '.$voice['about'] : '') }}</p>
                    @else
                        <p class="rg-admin-hint">No author: the texts are written as the site’s own.</p>
                    @endif
                    @if ($draft)
                        <p class="rg-admin-hint rg-admin-studio__context-warning" x-show="authorChanged" x-cloak>Another author is chosen: generating saves the draft first, so the texts are written in the new author’s voice.</p>
                    @endif
                </div>
            @endif

            @if ($state === 'ready' && ! $configured)
                <x-admin.ui.inline-notice tone="info">Text suggestions are not configured.</x-admin.ui.inline-notice>
            @elseif ($state === 'ready' && $draft)
                <div class="rg-admin-studio__context-part">
                    <span class="rg-admin-overline" id="rg-admin-studio-tone-label">Tone of new variants</span>
                    <div class="rg-admin-studio__tones" role="group" aria-labelledby="rg-admin-studio-tone-label">
                        @foreach ($tones as $option)
                            <button
                                type="button"
                                class="rg-admin-studio__tone"
                                aria-pressed="{{ $chosenTone->value === $option['value'] ? 'true' : 'false' }}"
                                x-bind:aria-pressed="$wire.tone === @js($option['value']) ? 'true' : 'false'"
                                x-on:click="$wire.set('tone', @js($option['value']))"
                                title="{{ $option['hint'] }}"
                            >{{ $option['label'] }}</button>
                        @endforeach
                    </div>
                    <p @class(['rg-admin-hint', 'rg-admin-studio__context-warning' => $otherTone])>
                        {{ $otherTone ? "The lists were written in {$batch['tone']->label()}. " : '' }}Picking a tone changes nothing until you generate again. The final title and description are never changed by it.
                    </p>
                </div>

                <div class="rg-admin-studio__context-part">
                    <div>
                        <x-admin.ui.button
                            :variant="$batch === null && ! $failed ? 'primary' : 'secondary'"
                            icon="sparkles"
                            :disabled="$busy"
                            x-on:click="ask()"
                            wire:loading.attr="disabled"
                            wire:target="generate"
                            aria-describedby="rg-admin-studio-suggestions-note"
                        >{{ $label }}</x-admin.ui.button>
                    </div>
                    <p id="rg-admin-studio-suggestions-note" class="rg-admin-hint">One request to OpenAI for {{ $count }} titles and {{ $count }} descriptions — a paid call.</p>
                </div>
            @endif
        </div>
    </x-admin.ui.card>

    @foreach (['titles' => ['titles', 'useTitle', 'Use this title', 'postTitle', '38px'], 'descriptions' => ['descriptions', 'useDescription', 'Use this description', 'postDescription', '58px']] as $kind => [$noun, $method, $action, $draftField, $placeholderHeight])
        <section class="rg-admin-card rg-admin-studio__suggestion-card" aria-labelledby="rg-admin-studio-suggestions-{{ $kind }}" data-suggestion-list="{{ $kind }}">
            <div class="rg-admin-card__header">
                <h2 id="rg-admin-studio-suggestions-{{ $kind }}" class="rg-admin-card__title">{{ $count }} {{ $noun }}</h2>
                <p @class(['rg-admin-card__description', 'rg-admin-studio__context-warning' => $lists === 'ready' && $otherTone])>{{ $meta }}</p>
            </div>

            @if ($lists === 'loading')
                <div class="rg-admin-studio__suggestion-waiting" role="status" aria-label="{{ $meta }}">
                    @foreach (range(1, $count) as $placeholder)
                        <x-admin.ui.skeleton shape="box" tone="soft" :height="$placeholderHeight" />
                    @endforeach
                </div>
            @elseif ($lists === 'failed')
                <div class="rg-admin-studio__suggestion-failure" role="status">
                    <span class="rg-admin-studio__suggestion-failure-icon" aria-hidden="true"><x-admin.ui.icon name="circle-alert" :size="17" /></span>
                    <p class="rg-admin-studio__suggestion-failure-title">Generation failed</p>
                    <p class="rg-admin-studio__suggestion-failure-text">{{ $phase === 'stale' ? 'The suggestions did not arrive. Try again.' : $error }} The final title and description are unchanged.</p>
                    @if ($draft && $configured)
                        <x-admin.ui.button size="sm" x-on:click="ask()" wire:loading.attr="disabled" wire:target="generate">Try again</x-admin.ui.button>
                    @endif
                </div>
            @elseif ($lists === 'ready')
                <ol class="rg-admin-studio__suggestion-list">
                    @foreach ($batch[$kind] as $index => $text)
                        <li wire:key="suggestion-{{ $kind }}-{{ $batch['id'] }}-{{ $index }}">
                            @if ($draft)
                                <button
                                    type="button"
                                    class="rg-admin-studio__suggestion rg-admin-studio__suggestion--choosable"
                                    x-data
                                    x-bind:class="{ 'rg-admin-studio__suggestion--used': $wire.$parent?.{{ $draftField }} === @js($text) }"
                                    wire:click="{{ $method }}({{ $batch['id'] }}, {{ $index }})"
                                >
                            @else
                                <div class="rg-admin-studio__suggestion">
                            @endif
                                <span class="rg-admin-studio__suggestion-number" aria-hidden="true">{{ $index + 1 }}</span>
                                <span class="rg-admin-studio__suggestion-text">@if ($draft)<span class="rg-admin-sr-only">{{ $action }}: </span>@endif{{ $text }}</span>
                                <span class="rg-admin-studio__suggestion-used" x-data x-show="$wire.$parent?.{{ $draftField }} === @js($text)" x-cloak>
                                    <x-admin.ui.icon name="circle-check" :size="16" /><span class="rg-admin-sr-only">In use</span>
                                </span>
                            @if ($draft)
                                </button>
                            @else
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="rg-admin-studio__suggestion-empty">
                    @if ($state === 'ready' && $configured && $draft)
                        Pick a tone and generate: one request writes {{ $count }} titles and {{ $count }} descriptions.
                    @else
                        No {{ $noun }} yet.
                    @endif
                </p>
            @endif
        </section>
    @endforeach
</div>
