{{--
    Photo analysis of the saved photo the editor shows (StudioPlanImageAnalysisCard):
    how far its analysis has got, what the analysis says, what the photo is,
    and the one action that fits — Re-run analysis or Try again, each a paid
    request of its own and never part of Save draft. The first analysis is
    asked for the moment a photo is stored. A re-run is shown as the first
    run was: what the analysis said before is gone from the card the moment
    it is asked for, and only the new result, or why there is none, takes its
    place. Read again every few seconds while an attempt is queued or
    running, and not at all otherwise.

    While a new photo is on its way — uploaded, processed or imported, as the
    Image step announces with `rg-studio-photo-phase` — the card says so, and
    shows nothing of the photo before.
--}}
@php
    $poll = \App\Livewire\Admin\StudioPlanImageAnalysisCard::POLL_SECONDS;
    $arriving = [
        'uploading' => ['upload', 'Uploading photo…'],
        'processing' => ['image', 'Processing photo…'],
        'importing' => ['link', 'Importing photo…'],
    ];
@endphp

<div
    class="rg-admin-studio__analysis"
    data-analysis="{{ $phase ?? $state }}"
    x-data="{ arriving: '' }"
    x-on:rg-studio-photo-phase.window="arriving = $event.detail?.phase ?? ''"
    @if ($polling) wire:poll.{{ $poll }}s.visible @endif
>
    <x-admin.ui.card flush title="Photo analysis" description="Technical context for the text generators. Not shown to visitors.">
        <x-slot:aside>
            <span x-show="arriving === ''">
                <x-admin.ui.badge :tone="$badge['tone']" :dot="$badge['dot']">{{ $badge['label'] }}</x-admin.ui.badge>
            </span>
        </x-slot:aside>

        <div class="rg-admin-card__body rg-admin-studio__stack">
            @foreach ($arriving as $arrivingPhase => [$icon, $text])
                <div x-show="arriving === '{{ $arrivingPhase }}'" x-cloak>
                    <x-admin.ui.inline-notice tone="info" :icon="$icon" role="status">{{ $text }}</x-admin.ui.inline-notice>
                </div>
            @endforeach

            <div class="rg-admin-studio__stack" x-show="arriving === ''">
                @if ($state === 'gone')
                    <p class="rg-admin-studio__empty">This draft was deleted.</p>
                @elseif ($state === 'no_image')
                    @if ($configured)
                        <p class="rg-admin-studio__empty rg-admin-studio__analysis-idle">The analysis starts as soon as a photo is added.</p>
                    @else
                        <x-admin.ui.inline-notice tone="info">Photo analysis is not configured.</x-admin.ui.inline-notice>
                    @endif
                @elseif ($state === 'changed')
                    <x-admin.ui.inline-notice tone="warning">
                        The photo was changed after this draft was opened. Reload the draft to analyse the photo it has now.
                        <x-slot:actions>
                            <x-admin.ui.button size="sm" icon="refresh-cw" x-on:click="$dispatch('reload-studio-plan')">Reload</x-admin.ui.button>
                        </x-slot:actions>
                    </x-admin.ui.inline-notice>
                @else
                    @if ($phase === 'queued' || $phase === 'running')
                        <div class="rg-admin-studio__analysis-waiting" aria-hidden="true">
                            <x-admin.ui.skeleton width="92%" />
                            <x-admin.ui.skeleton width="78%" />
                            <x-admin.ui.skeleton width="64%" />
                        </div>
                    @endif

                    @if ($phase === 'queued')
                        <x-admin.ui.inline-notice tone="info" icon="clock" role="status">Waiting for analysis…</x-admin.ui.inline-notice>
                    @elseif ($phase === 'running')
                        <x-admin.ui.inline-notice tone="info" icon="activity" role="status">Analysing photo…</x-admin.ui.inline-notice>
                    @elseif ($phase === 'stale')
                        <x-admin.ui.inline-notice tone="warning" role="status">The analysis did not finish. Try again.</x-admin.ui.inline-notice>
                    @elseif ($phase === 'failed')
                        <x-admin.ui.inline-notice tone="warning" role="status">{{ $error }}</x-admin.ui.inline-notice>
                    @endif

                    @if ($sections !== null && $phase === 'completed')
                        <dl class="rg-admin-studio__analysis-sections" aria-label="Photo analysis">
                            @foreach ($sections as $section)
                                <div class="rg-admin-studio__analysis-section">
                                    <dt class="rg-admin-studio__analysis-label">{{ $section['label'] }}</dt>
                                    <dd class="rg-admin-studio__analysis-value">
                                        @if (isset($section['items']))
                                            @if ($section['items'] === [])
                                                <span class="rg-admin-studio__empty">None noted</span>
                                            @else
                                                <ul class="rg-admin-studio__analysis-list">
                                                    @foreach ($section['items'] as $item)
                                                        <li>{{ $item }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        @elseif ($section['text'] === '')
                                            <span class="rg-admin-studio__empty">None noted</span>
                                        @else
                                            {{ $section['text'] }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                        <p class="rg-admin-hint">Analysed {{ $analysedAt }}{{ $model !== null ? ' with '.$model : '' }}.</p>
                    @elseif ($phase === null && $configured)
                        <p class="rg-admin-studio__empty">This photo has not been analysed yet.</p>
                    @endif

                    @if (! $configured)
                        <x-admin.ui.inline-notice tone="info">Photo analysis is not configured.</x-admin.ui.inline-notice>
                    @elseif ($draft && ($phase === null || $phase === 'completed'))
                        <div class="rg-admin-studio__analysis-actions">
                            <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="analyze('{{ $attemptId }}')" wire:loading.attr="disabled" wire:target="analyze" aria-describedby="rg-admin-studio-analysis-note">Re-run analysis</x-admin.ui.button>
                            <p id="rg-admin-studio-analysis-note" class="rg-admin-hint">Re-running sends the photo to OpenAI again — a new, paid request.</p>
                        </div>
                    @elseif ($draft && ($phase === 'failed' || $phase === 'stale'))
                        <div class="rg-admin-studio__analysis-actions">
                            <x-admin.ui.button size="sm" icon="rotate-ccw" wire:click="analyze('{{ $attemptId }}')" wire:loading.attr="disabled" wire:target="analyze" aria-describedby="rg-admin-studio-analysis-note">Try again</x-admin.ui.button>
                            <p id="rg-admin-studio-analysis-note" class="rg-admin-hint">The photo is sent to OpenAI again — a new, paid request.</p>
                        </div>
                    @endif
                @endif
            </div>
        </div>

        @if ($photo !== [])
            <div class="rg-admin-card__body rg-admin-studio__analysis-photo" x-show="arriving === ''">
                <dl class="rg-admin-detail-list" aria-label="The photo">
                    @foreach ($photo as [$icon, $label, $value])
                        <div class="rg-admin-detail-row">
                            <x-admin.ui.icon :name="$icon" class="rg-admin-detail-row__icon" />
                            <dt class="rg-admin-detail-row__label">{{ $label }}</dt>
                            <dd class="rg-admin-detail-row__value rg-admin-studio__analysis-fact">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </x-admin.ui.card>
</div>
