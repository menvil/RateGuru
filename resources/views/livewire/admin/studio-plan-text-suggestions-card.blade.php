{{--
    AI text suggestions on the Post step (StudioPlanTextSuggestionsCard): the
    tone of the next batch and Generate suggestions — then Generate 10 more,
    or Try again — and every batch written from the photo and analysis the
    plan has now, newest first, its titles and its descriptions side by side,
    each with Use this title or Use this description. Using one puts it in
    the draft's final title or description, unsaved, marked here as in use;
    nothing is generated or saved by itself. Read again every few seconds
    while a batch, or the analysis it waits for, is on its way, and not at
    all otherwise. Every text is drawn escaped.
--}}
@php
    $poll = \App\Livewire\Admin\StudioPlanTextSuggestionsCard::POLL_SECONDS;
    $busy = $phase === 'queued' || $phase === 'running';
    $label = match (true) {
        $phase === 'failed' || $phase === 'stale' => 'Try again',
        $batches !== [] => "Generate {$count} more",
        default => 'Generate suggestions',
    };
@endphp

<div
    class="rg-admin-studio__suggestions"
    data-suggestions="{{ $phase ?? $state }}"
    @if ($polling) wire:poll.{{ $poll }}s.visible @endif
>
    <x-admin.ui.card flush title="AI text suggestions" description="Titles and descriptions written from the photo analysis. Pick any title and any description; nothing changes until you use one, and nothing is saved until Save draft.">
        <div class="rg-admin-card__body rg-admin-studio__stack">
            @if ($state === 'gone')
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
            @elseif ($state === 'analysis_pending')
                <x-admin.ui.inline-notice tone="info" icon="clock" role="status">Text suggestions will be available when photo analysis is complete.</x-admin.ui.inline-notice>
            @elseif (! $configured)
                <x-admin.ui.inline-notice tone="info">Text suggestions are not configured.</x-admin.ui.inline-notice>
            @elseif ($draft)
                <div class="rg-admin-studio__suggestions-bar">
                    <div class="rg-admin-field">
                        <span class="rg-admin-label" id="rg-admin-studio-tone-label">Tone of new variants</span>
                        <div class="rg-admin-studio__tones" role="group" aria-labelledby="rg-admin-studio-tone-label">
                            @foreach ($tones as $option)
                                <button
                                    type="button"
                                    class="rg-admin-studio__tone"
                                    aria-pressed="{{ $tone === $option['value'] ? 'true' : 'false' }}"
                                    x-bind:aria-pressed="$wire.tone === @js($option['value']) ? 'true' : 'false'"
                                    x-on:click="$wire.tone = @js($option['value'])"
                                    title="{{ $option['hint'] }}"
                                >{{ $option['label'] }}</button>
                            @endforeach
                        </div>
                        <p class="rg-admin-hint">Picking a tone changes nothing until you generate again. The final title and description are never changed by it.</p>
                    </div>
                    <div class="rg-admin-studio__suggestions-action">
                        <x-admin.ui.button
                            :variant="$batches === [] ? 'primary' : 'secondary'"
                            icon="sparkles"
                            :disabled="$busy"
                            wire:click="generate"
                            wire:loading.attr="disabled"
                            wire:target="generate"
                            aria-describedby="rg-admin-studio-suggestions-note"
                        >{{ $label }}</x-admin.ui.button>
                        <p id="rg-admin-studio-suggestions-note" class="rg-admin-hint">One request to OpenAI for {{ $count }} titles and {{ $count }} descriptions — a paid call.</p>
                    </div>
                </div>

                @if ($phase === 'queued')
                    <x-admin.ui.inline-notice tone="info" icon="clock" role="status">Waiting for text suggestions…</x-admin.ui.inline-notice>
                @elseif ($phase === 'running')
                    <x-admin.ui.inline-notice tone="info" icon="sparkles" role="status">Generating suggestions…</x-admin.ui.inline-notice>
                @elseif ($phase === 'stale')
                    <x-admin.ui.inline-notice tone="warning" role="status">The suggestions did not arrive. Try again.</x-admin.ui.inline-notice>
                @elseif ($phase === 'failed')
                    <x-admin.ui.inline-notice tone="warning" role="status">{{ $error }} The final title and description are unchanged.</x-admin.ui.inline-notice>
                @endif
            @endif
        </div>

        @if ($batches !== [] && in_array($state, ['ready', 'analysis_pending'], true))
            <div class="rg-admin-card__body rg-admin-studio__suggestion-columns">
                @foreach (['titles' => ['Titles', 'useTitle', 'Use this title', 'postTitle'], 'descriptions' => ['Descriptions', 'useDescription', 'Use this description', 'postDescription']] as $kind => [$heading, $method, $action, $draftField])
                    <section class="rg-admin-studio__suggestion-column" aria-labelledby="rg-admin-studio-suggestions-{{ $kind }}">
                        <h3 id="rg-admin-studio-suggestions-{{ $kind }}" class="rg-admin-studio__suggestion-heading">{{ $heading }}</h3>
                        @foreach ($batches as $batch)
                            <div class="rg-admin-studio__suggestion-set" wire:key="suggestions-{{ $kind }}-{{ $batch['id'] }}">
                                <p class="rg-admin-studio__suggestion-meta">Set {{ $batch['set'] }} · {{ $batch['tone'] }}{{ $batch['at'] !== null ? ' · '.$batch['at'] : '' }}</p>
                                <ol class="rg-admin-studio__suggestion-list">
                                    @foreach ($batch[$kind] as $index => $text)
                                        <li
                                            class="rg-admin-studio__suggestion"
                                            x-data
                                            x-bind:class="{ 'rg-admin-studio__suggestion--used': $wire.$parent?.{{ $draftField }} === @js($text) }"
                                        >
                                            <span class="rg-admin-studio__suggestion-number" aria-hidden="true">{{ $index + 1 }}</span>
                                            <span class="rg-admin-studio__suggestion-text">{{ $text }}</span>
                                            <span class="rg-admin-studio__suggestion-used" x-show="$wire.$parent?.{{ $draftField }} === @js($text)" x-cloak>
                                                <x-admin.ui.icon name="circle-check" :size="14" />In use
                                            </span>
                                            @if ($draft)
                                                <x-admin.ui.button size="sm" variant="ghost" class="rg-admin-studio__suggestion-use" wire:click="{{ $method }}({{ $batch['id'] }}, {{ $index }})">{{ $action }}</x-admin.ui.button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </div>
        @endif
    </x-admin.ui.card>
</div>
