@php
    // tone => [label, dot]. The tone of each label comes from the reference's status table.
    $badgeRows = [
        'success' => [['Published', true], ['Active', true], ['Visible', true], ['Enabled', true], ['Completed', true], ['Saved', true], ['Resolved', false]],
        'warning' => [['Pending', false], ['Open', false], ['Limited', false], ['Missing', false], ['Degraded', true]],
        'danger' => [['Rejected', false], ['Banned', false], ['Failed', false], ['Critical', false], ['Not configured', false]],
        'neutral' => [['Hidden', false], ['Ignored', false], ['Disabled', false], ['Inactive', false], ['Shadowbanned', false], ['Removal finalized', false]],
        'outline' => [['Draft', false], ['Deleted by author', false], ['Archived', false], ['Default', false]],
        'info' => [['AI suggestion · not saved', true], ['Running', false], ['Info', false]],
    ];

    $bars = [
        ['63', '', '63% · 66 of 104', '63% translated', ''],
        ['100', 'rg-admin-progress__bar--complete', '100% · complete', 'Complete', ''],
        ['96', 'rg-admin-progress__bar--invalid', '96% · catalog invalid', 'Catalog invalid, 96%', 'color: var(--rg-admin-status-danger-fg)'],
        ['41', 'rg-admin-progress__bar--running', 'Audit running · 41%', 'Audit running, 41%', 'color: var(--rg-admin-status-info-fg)'],
    ];
@endphp

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['STS-01']])
    <div class="rg-admin-kit__stack">
        @foreach ($badgeRows as $tone => $badges)
            <div class="rg-admin-kit__labelled">
                <span class="rg-admin-kit__caption">{{ $tone }}</span>
                <div class="rg-admin-kit__wrap">
                    @foreach ($badges as [$label, $dot])
                        <x-admin.ui.badge :tone="$tone" :dot="$dot">{{ $label }}</x-admin.ui.badge>
                    @endforeach
                </div>
            </div>
        @endforeach
        <div class="rg-admin-kit__labelled">
            <span class="rg-admin-kit__caption">with note</span>
            <div class="rg-admin-badge-note">
                <x-admin.ui.badge tone="neutral">Hidden</x-admin.ui.badge>
                <span class="rg-admin-badge-note__text">Restorable until 16 Oct</span>
            </div>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['STS-02']])
    <div class="rg-admin-kit__row rg-admin-kit__row--center">
        <ul class="rg-admin-nav-section__items rg-admin-kit__frame" aria-label="Navigation counters" style="width: 260px; padding: 6px">
            <li>
                <a class="rg-admin-nav-item" href="#STS-02" aria-current="page">
                    <x-admin.ui.icon name="image" :size="18" />
                    <span class="rg-admin-nav-item__label">Posts</span>
                    <x-admin.ui.badge tone="warning" pill>8<span class="rg-admin-sr-only"> pending</span></x-admin.ui.badge>
                </a>
            </li>
            <li>
                <a class="rg-admin-nav-item" href="#STS-02">
                    <x-admin.ui.icon name="hard-drive" :size="18" />
                    <span class="rg-admin-nav-item__label">Media diagnostics</span>
                    <x-admin.ui.badge tone="danger" pill>2<span class="rg-admin-sr-only"> critical</span></x-admin.ui.badge>
                </a>
            </li>
            <li>
                <a class="rg-admin-nav-item" href="#STS-02">
                    <x-admin.ui.icon name="languages" :size="18" />
                    <span class="rg-admin-nav-item__label">Translation Center</span>
                    <x-admin.ui.badge tone="neutral" pill>48<span class="rg-admin-sr-only"> missing</span></x-admin.ui.badge>
                </a>
            </li>
        </ul>
        <div class="rg-admin-kit__row rg-admin-kit__row--center">
            <x-admin.ui.badge tone="warning" icon="flag">2<span class="rg-admin-sr-only"> reports</span></x-admin.ui.badge>
            <x-admin.ui.badge tone="danger" icon="flag">4<span class="rg-admin-sr-only"> reports</span></x-admin.ui.badge>
            <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">No reports</span></span>
            <span class="rg-admin-kit__caption">report chip · 1–2 · 3+ · none</span>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['STS-03']])
    <div class="rg-admin-kit__stack">
        <div class="rg-admin-kit__grid rg-admin-kit__grid--wide">
            @foreach ($bars as [$value, $modifier, $text, $label, $textStyle])
                <div class="rg-admin-kit__stack" style="gap: 8px">
                    <div class="rg-admin-progress" role="img" aria-label="{{ $label }}">
                        <span @class(['rg-admin-progress__bar', $modifier]) style="width: {{ $value }}%"></span>
                    </div>
                    <span class="rg-admin-table__meta rg-admin-table__meta--small" style="color: var(--rg-admin-text-secondary); {{ $textStyle }}">{{ $text }}</span>
                </div>
            @endforeach
        </div>
        <div class="rg-admin-kit__stack" style="gap: 12px">
            <div class="rg-admin-progress rg-admin-progress--summary" role="img" aria-label="Translated 66, missing 38">
                <span class="rg-admin-progress__bar" style="width: 63.46%"></span>
                <span class="rg-admin-progress__bar rg-admin-progress__bar--missing" style="width: 36.54%"></span>
            </div>
            <ul class="rg-admin-progress-legend">
                <li class="rg-admin-progress-legend__item"><span class="rg-admin-progress-legend__swatch"></span>Translated 66</li>
                <li class="rg-admin-progress-legend__item"><span class="rg-admin-progress-legend__swatch rg-admin-progress-legend__swatch--missing"></span>Missing 38</li>
            </ul>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['STS-05']])
    <div class="rg-admin-kit__stack" style="gap: 4px">
        <span class="rg-admin-table__primary">Rabbits &amp; rodents</span>
        <div class="rg-admin-locale-chips">
            <span class="rg-admin-locale-chip" title="Русский: Кролики и грызуны">ru</span>
            <span class="rg-admin-locale-chip rg-admin-locale-chip--missing" title="Български: missing">bg<span class="rg-admin-sr-only">, missing</span></span>
            <span class="rg-admin-locale-chip" title="Deutsch: Kaninchen &amp; Nager">de</span>
            <span class="rg-admin-locale-chips__summary">1 missing</span>
        </div>
    </div>
@endcomponent
