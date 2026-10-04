@php
    // [label, icon, count, count tone, active]
    $sections = [
        'Moderation' => [
            ['Posts', 'image', 8, 'warning', true],
            ['Comments', 'message-square', 3, 'warning', false],
            ['Reports', 'flag', 9, 'warning', false],
            ['Users', 'users', null, null, false],
        ],
        'Localization' => [
            ['Languages', 'globe', null, null, false],
            ['Translation Center', 'languages', 48, 'neutral', false],
        ],
    ];

    // [label, icon, status dot, separator before]
    $rail = [
        ['Posts · 8 pending', 'image', 'warning', false],
        ['Users', 'users', null, false],
        ['Languages', 'globe', null, true],
        ['Translation Center · 48 missing', 'languages', null, false],
        ['Media diagnostics · 2 critical', 'hard-drive', 'danger', true],
    ];

    $tabs = [
        ['id' => 'all', 'label' => 'All', 'count' => '1,262'],
        ['id' => 'pending', 'label' => 'Pending', 'count' => 8],
        ['id' => 'published', 'label' => 'Published', 'count' => '1,217'],
        ['id' => 'hidden', 'label' => 'Hidden', 'count' => 2],
        ['id' => 'reported', 'label' => 'Reported', 'count' => 4],
    ];
@endphp

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['NAV-01']])
    <div class="rg-admin-kit__shell">
        <div class="rg-admin-sidebar">
            <div style="padding: 9px 12px; border-bottom: 1px solid var(--rg-admin-border-default)">
                <div class="rg-admin-workspace">
                    <span style="flex: 1; min-width: 0">
                        <span class="rg-admin-workspace__name">RateGuru</span>
                        <span class="rg-admin-workspace__subtitle">Admin · reference specimen</span>
                    </span>
                </div>
            </div>
            <nav aria-label="Admin navigation specimen" style="padding: 14px 12px">
                <div class="rg-admin-nav">
                    @foreach ($sections as $section => $items)
                        <div class="rg-admin-nav-section">
                            <p class="rg-admin-nav-section__label" id="kit-nav-{{ \Illuminate\Support\Str::slug($section) }}">{{ $section }}</p>
                            <ul class="rg-admin-nav-section__items" aria-labelledby="kit-nav-{{ \Illuminate\Support\Str::slug($section) }}">
                            @foreach ($items as [$label, $icon, $count, $tone, $active])
                                <li>
                                    <a class="rg-admin-nav-item" href="#NAV-01" @if ($active) aria-current="page" @endif>
                                        <x-admin.ui.icon :name="$icon" :size="18" />
                                        <span class="rg-admin-nav-item__label">{{ $label }}</span>
                                        @if ($count !== null)
                                            <x-admin.ui.badge :tone="$tone" pill>{{ $count }}</x-admin.ui.badge>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </nav>
        </div>

        <ul class="rg-admin-rail" aria-label="Icon rail specimen, below 1280 px">
            @foreach ($rail as [$label, $icon, $dot, $separated])
                @if ($separated)
                    <li class="rg-admin-rail__separator" aria-hidden="true"></li>
                @endif
                <li>
                    <a class="rg-admin-rail__item" href="#NAV-01" aria-label="{{ $label }}" title="{{ $label }}" @if ($loop->first) aria-current="page" @endif>
                        <x-admin.ui.icon :name="$icon" :size="18" />
                        @if ($dot !== null)
                            <span @class(['rg-admin-rail__dot', 'rg-admin-rail__dot--danger' => $dot === 'danger'])></span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['NAV-02']])
    <div class="rg-admin-kit__stack">
        <div class="rg-admin-topbar rg-admin-kit__frame">
            <nav class="rg-admin-breadcrumb" aria-label="Breadcrumb">
                <ol class="rg-admin-breadcrumb__list">
                    <li class="rg-admin-breadcrumb__item"><a class="rg-admin-breadcrumb__link" href="#NAV-02">Moderation</a></li>
                    <li class="rg-admin-breadcrumb__item">
                        <x-admin.ui.icon name="chevron-right" :size="14" class="rg-admin-breadcrumb__separator" />
                        <span class="rg-admin-breadcrumb__current" aria-current="page">Posts</span>
                    </li>
                </ol>
            </nav>
            <div class="rg-admin-topbar__actions">
                <span class="rg-admin-topbar__meta">Updated 09:41</span>
                <x-admin.ui.button icon="refresh-cw">Refresh</x-admin.ui.button>
            </div>
        </div>
        <div class="rg-admin-topbar rg-admin-kit__frame">
            <nav class="rg-admin-breadcrumb" aria-label="Breadcrumb">
                <ol class="rg-admin-breadcrumb__list">
                    <li class="rg-admin-breadcrumb__item"><a class="rg-admin-breadcrumb__link" href="#NAV-02">Localization</a></li>
                    <li class="rg-admin-breadcrumb__item">
                        <x-admin.ui.icon name="chevron-right" :size="14" class="rg-admin-breadcrumb__separator" />
                        <span class="rg-admin-breadcrumb__current" aria-current="page">Translation Center</span>
                    </li>
                </ol>
            </nav>
            <div class="rg-admin-topbar__actions">
                <span class="rg-admin-topbar__meta rg-admin-topbar__meta--unsaved">2 unsaved changes</span>
                <x-admin.ui.button>Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="primary">Save changes</x-admin.ui.button>
            </div>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['NAV-03']])
    <x-admin.ui.tabs label="Post status" active="pending" :items="$tabs" />
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['NAV-04']])
    <nav class="rg-admin-pagination rg-admin-kit__frame" aria-label="Pagination">
        <span class="rg-admin-pagination__range">1–25 of 1,231</span>
        <ul class="rg-admin-pagination__pages">
            <li><x-admin.ui.icon-button icon="chevron-left" label="Previous page" variant="ghost" size="sm" disabled /></li>
            <li><a class="rg-admin-pagination__page" href="#NAV-04" aria-current="page">1</a></li>
            <li><a class="rg-admin-pagination__page" href="#NAV-04">2</a></li>
            <li><a class="rg-admin-pagination__page" href="#NAV-04">3</a></li>
            <li class="rg-admin-pagination__gap" aria-hidden="true">…</li>
            <li><a class="rg-admin-pagination__page" href="#NAV-04">50</a></li>
            <li><x-admin.ui.icon-button icon="chevron-right" label="Next page" variant="ghost" size="sm" href="#NAV-04" /></li>
        </ul>
    </nav>
@endcomponent
