{{--
    Photo analysis of the saved photo the editor shows (StudioPlanImageAnalysisCard):
    whether it is analysed, what the analysis says, and the one action that
    fits — Analyze photo, Re-run analysis or Try again, each a request of
    its own and never part of Save draft. Read again every few seconds while
    an attempt is queued or running, and not at all otherwise.
--}}
@php
    $poll = \App\Livewire\Admin\StudioPlanImageAnalysisCard::POLL_SECONDS;
@endphp

<div
    class="rg-admin-studio__stack rg-admin-studio__analysis"
    data-analysis="{{ $phase ?? $state }}"
    @if ($polling) wire:poll.{{ $poll }}s.visible @endif
>
    @if ($state === 'gone')
        <p class="rg-admin-studio__empty">This draft was deleted.</p>
    @elseif ($state === 'no_image')
        <x-admin.ui.inline-notice tone="info">Save a photo to this draft before analysing it.</x-admin.ui.inline-notice>
        <div><x-admin.ui.button icon="sparkles" disabled>Analyze photo</x-admin.ui.button></div>
    @elseif ($state === 'changed')
        <x-admin.ui.inline-notice tone="warning">
            The photo was changed after this draft was opened. Reload the draft to analyse the photo it has now.
            <x-slot:actions>
                <x-admin.ui.button size="sm" icon="refresh-cw" x-on:click="$dispatch('reload-studio-plan')">Reload</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.inline-notice>
    @else
        @if ($phase === 'queued')
            <x-admin.ui.inline-notice tone="info" icon="clock" role="status">Waiting for analysis…</x-admin.ui.inline-notice>
        @elseif ($phase === 'running')
            <x-admin.ui.inline-notice tone="info" icon="activity" role="status">Analysing photo…</x-admin.ui.inline-notice>
        @elseif ($phase === 'stale')
            <x-admin.ui.inline-notice tone="warning" role="status">The analysis did not finish. Try again.</x-admin.ui.inline-notice>
        @elseif ($phase === 'failed')
            <x-admin.ui.inline-notice tone="warning" role="status">{{ $error }}</x-admin.ui.inline-notice>
        @endif

        @if ($sections !== null)
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
        @elseif ($draft && $phase === null)
            <div class="rg-admin-studio__analysis-actions">
                <x-admin.ui.button variant="primary" icon="sparkles" wire:click="analyze" wire:loading.attr="disabled" wire:target="analyze" aria-describedby="rg-admin-studio-analysis-note">Analyze photo</x-admin.ui.button>
                <p id="rg-admin-studio-analysis-note" class="rg-admin-hint">The selected photo will be sent to OpenAI for analysis.</p>
            </div>
        @elseif ($draft && $phase === 'completed')
            <div class="rg-admin-studio__analysis-actions">
                <x-admin.ui.button icon="refresh-cw" wire:click="analyze('{{ $attemptId }}')" wire:loading.attr="disabled" wire:target="analyze" aria-describedby="rg-admin-studio-analysis-note">Re-run analysis</x-admin.ui.button>
                <p id="rg-admin-studio-analysis-note" class="rg-admin-hint">Re-running sends the photo to OpenAI again — a new, paid request.</p>
            </div>
        @elseif ($draft && ($phase === 'failed' || $phase === 'stale'))
            <div class="rg-admin-studio__analysis-actions">
                <x-admin.ui.button icon="rotate-ccw" wire:click="analyze('{{ $attemptId }}')" wire:loading.attr="disabled" wire:target="analyze" aria-describedby="rg-admin-studio-analysis-note">Try again</x-admin.ui.button>
                <p id="rg-admin-studio-analysis-note" class="rg-admin-hint">The selected photo will be sent to OpenAI for analysis.</p>
            </div>
        @endif
    @endif
</div>
