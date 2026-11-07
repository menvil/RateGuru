{{--
    Background generations: what Generate missing is doing now, from the
    temporary store — counts, a status and times, nothing of what is being
    translated. Read again every few seconds while one is queued or running,
    and not at all once none is.
--}}
<section
    class="rg-admin-card rg-admin-translation-ai__generations"
    aria-labelledby="rg-admin-translation-ai-generations"
    @if ($polling) wire:poll.{{ \App\Livewire\Admin\TranslationAiGenerations::POLL_SECONDS }}s.visible @endif
>
    <header class="rg-admin-translation-ai__section-head">
        <div class="rg-admin-translation-ai__section-text">
            <h2 id="rg-admin-translation-ai-generations" class="rg-admin-card__title">Background generations</h2>
            <p class="rg-admin-card__description">
                Generate missing, read from the temporary store: the generation of each language now, and your own until its results expire.
                There is no history beyond that.
            </p>
        </div>
        <span class="rg-admin-translation-ai__checked">Checked {{ $checkedAt }}</span>
    </header>

    @if (! $available)
        <x-admin.ui.inline-notice tone="warning" strip role="status">
            Temporarily unavailable. The store Generate missing keeps its work in cannot be reached right now; the rest of this page is not affected.
        </x-admin.ui.inline-notice>
    @elseif ($rows === [])
        <x-admin.ui.empty-state icon="layers" title="No background generations">
            Generate missing in Translation Center starts one. Its progress shows here while it runs.
        </x-admin.ui.empty-state>
    @else
        <ul class="rg-admin-translation-ai__generation-list" aria-label="Background generations">
            @foreach ($rows as $row)
                <li class="rg-admin-translation-ai__generation" data-locale="{{ $row['locale'] }}">
                    <div class="rg-admin-translation-ai__generation-language">
                        <span class="rg-admin-translation-ai__generation-flag" aria-hidden="true">{{ $row['flag'] }}</span>
                        <span class="rg-admin-translation-ai__stack">
                            <span class="rg-admin-table__primary">{{ $row['language'] }} — {{ $row['native'] }}</span>
                            <span class="rg-admin-table__meta"><span class="rg-admin-mono">{{ $row['locale'] }}</span> · {{ $row['owner'] }} · {{ $row['started'] }}</span>
                        </span>
                    </div>

                    <div class="rg-admin-translation-ai__generation-progress">
                        <x-admin.ui.badge :tone="$row['status']['tone']" :dot="$row['status']['dot']">{{ $row['status']['label'] }}</x-admin.ui.badge>
                        <span class="rg-admin-progress rg-admin-translation-ai__generation-bar" aria-hidden="true">
                            <span @class([
                                'rg-admin-progress__bar',
                                'rg-admin-progress__bar--running' => $row['active'],
                                'rg-admin-progress__bar--complete' => ! $row['active'] && $row['percentage'] === 100,
                            ]) style="width: {{ $row['percentage'] }}%"></span>
                        </span>
                        <span class="rg-admin-translation-ai__generation-figure">{{ $row['processed'] }} of {{ $row['total'] }} processed</span>
                    </div>

                    <dl class="rg-admin-translation-ai__generation-counts">
                        @foreach ($row['counts'] as $count)
                            <div>
                                <dt>{{ $count['label'] }}</dt>
                                <dd>{{ $count['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="rg-admin-translation-ai__generation-expiry">
                        <x-admin.ui.icon name="clock" :size="14" />
                        <span>Results kept until {{ $row['expires'] }}</span>
                        @if ($row['review'] !== null)
                            <a class="rg-admin-link" href="{{ $row['review'] }}">Review in Translation Center</a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
