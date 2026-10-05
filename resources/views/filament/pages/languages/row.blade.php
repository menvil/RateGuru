@php
    /*
     * One installed language (TBL-01). Which badge a state gets is decided
     * here and nowhere else on the screen: enabled is success with the live
     * dot, disabled is neutral, the default is marked with an outline badge.
     */
    $catalog = $row['catalog'];
    $project = $row['project'];
    $valid = $catalog->isComplete();
    $application = $catalog->percentage();
    $content = $project->percentage();
    $id = 'rg-admin-language-'.$row['code'];

    [$statusLabel, $statusTone, $statusDot] = $row['enabled'] ? ['Enabled', 'success', true] : ['Disabled', 'neutral', false];
    $statusNote = match (true) {
        $row['reference'] => 'Reference language',
        $row['enabled'] => 'Offered to visitors',
        default => 'Not offered to visitors',
    };
@endphp

<div id="{{ $id }}" class="rg-admin-table__row" role="row" wire:key="language-{{ $row['code'] }}">
    <div class="rg-admin-table__cell" role="cell">
        <div class="rg-admin-languages__language">
            {{-- The flag only accompanies the names; it never identifies the language alone. --}}
            <span class="rg-admin-languages__flag" aria-hidden="true">{{ $row['flag'] }}</span>
            <div class="rg-admin-languages__names">
                <div @class(['rg-admin-table__primary', 'rg-admin-languages__name--off' => ! $row['enabled']])>{{ $row['label'] }}</div>
                <div class="rg-admin-table__meta" lang="{{ $row['code'] }}">{{ $row['native'] }}</div>
            </div>
        </div>
    </div>

    <div class="rg-admin-table__cell rg-admin-languages__code" role="cell">{{ $row['code'] }}</div>

    <div class="rg-admin-table__cell" role="cell">
        <div class="rg-admin-badge-note">
            <span class="rg-admin-languages__badges">
                <x-admin.ui.badge :tone="$statusTone" :dot="$statusDot">{{ $statusLabel }}</x-admin.ui.badge>
                @if ($row['default'])
                    <x-admin.ui.badge tone="outline">Default</x-admin.ui.badge>
                @endif
            </span>
            <span class="rg-admin-badge-note__text">{{ $statusNote }}</span>
        </div>
    </div>

    {{-- A catalog with issues is invalid whatever share of its lines it has. --}}
    <div class="rg-admin-table__cell" role="cell">
        <div class="rg-admin-languages__progress">
            <div class="rg-admin-progress rg-admin-languages__bar rg-admin-languages__bar--application" aria-hidden="true">
                <span
                    @class([
                        'rg-admin-progress__bar',
                        'rg-admin-progress__bar--invalid' => ! $valid,
                        'rg-admin-progress__bar--complete' => $valid && $application === 100,
                    ])
                    style="width: {{ $application }}%"
                ></span>
            </div>
            <span @class(['rg-admin-languages__figure', 'rg-admin-languages__figure--invalid' => ! $valid])>
                {{ $application }}% · {{ $valid ? 'valid' : 'catalog invalid' }}
            </span>
        </div>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <div class="rg-admin-languages__progress">
            <div class="rg-admin-progress rg-admin-languages__bar rg-admin-languages__bar--project" aria-hidden="true">
                <span
                    @class(['rg-admin-progress__bar', 'rg-admin-progress__bar--complete' => $content === 100])
                    style="width: {{ $content }}%"
                ></span>
            </div>
            <span class="rg-admin-languages__figure">
                {{ $content }}% · {{ number_format($project->translated) }} of {{ number_format($project->required) }}
            </span>
        </div>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['missing'] > 0)
            <button type="button" class="rg-admin-languages__missing" wire:click="showMissing('{{ $row['code'] }}')">
                {{ number_format($row['missing']) }} missing<span class="rg-admin-sr-only"> in {{ $row['label'] }}</span>
                <x-admin.ui.icon name="chevron-right" :size="14" />
            </button>
        @elseif (! $valid)
            <button type="button" class="rg-admin-languages__missing rg-admin-languages__missing--invalid" wire:click="showMissing('{{ $row['code'] }}')">
                Catalog issue<span class="rg-admin-sr-only"> in {{ $row['label'] }}</span>
                <x-admin.ui.icon name="chevron-right" :size="14" />
            </button>
        @else
            <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">Nothing missing</span></span>
        @endif
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        @if ($row['default'])
            <span class="rg-admin-languages__always-on" title="{{ $row['label'] }} is the default language and is always enabled.">
                <x-admin.ui.icon name="lock" :size="14" />
                Always on
            </span>
        @elseif ($row['enabled'])
            <x-admin.ui.button size="sm" wire:click="askToDisable('{{ $row['code'] }}')">
                Disable<span class="rg-admin-sr-only"> {{ $row['label'] }}</span>
            </x-admin.ui.button>
        @elseif ($valid)
            <x-admin.ui.button size="sm" :icon="$row['missing'] > 0 ? 'triangle-alert' : 'check'" wire:click="askToEnable('{{ $row['code'] }}')">
                Enable<span class="rg-admin-sr-only"> {{ $row['label'] }}</span>
            </x-admin.ui.button>
        @else
            {{-- An unavailable action stays visible, disabled, with its reason. --}}
            <div class="rg-admin-languages__blocked">
                <x-admin.ui.button
                    size="sm"
                    icon="lock"
                    disabled
                    :aria-describedby="$id.'-blocked'"
                    title="Its application translations break the catalog contract. Fix the release first."
                >
                    Enable<span class="rg-admin-sr-only"> {{ $row['label'] }}</span>
                </x-admin.ui.button>
                <span id="{{ $id }}-blocked" class="rg-admin-languages__hint">Fix the release first.</span>
            </div>
        @endif
    </div>
</div>
