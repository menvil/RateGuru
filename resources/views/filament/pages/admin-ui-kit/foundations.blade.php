@php
    $grays = [
        ['gray-0', '#FFFFFF'], ['gray-50', '#F6F7FB'], ['gray-100', '#F3F4F9'], ['gray-200', '#E1E4EB'], ['gray-300', '#CACFD8'],
        ['gray-400', '#99A0AE'], ['gray-500', '#7A818E'], ['gray-600', '#525866'], ['gray-950', '#0E121B'],
    ];

    $statuses = [
        ['Success', 'success', '--rg-admin-status-success-bg · -fg'],
        ['Warning', 'warning', '--rg-admin-status-warning-bg · -fg'],
        ['Danger', 'danger', '--rg-admin-status-danger-bg · -fg'],
        ['Info · admin', 'info', '--rg-admin-status-info-bg · -fg'],
    ];

    $type = [
        ['title 24/32 · 500', 'font-size: var(--rg-admin-text-title); line-height: var(--rg-admin-leading-title); font-weight: 500; letter-spacing: var(--rg-admin-tracking-tight)', 'Rating groups'],
        ['stat 20/28 · 500', 'font-size: var(--rg-admin-text-stat); line-height: var(--rg-admin-leading-stat); font-weight: 500; letter-spacing: var(--rg-admin-tracking-tight); font-variant-numeric: tabular-nums', '1,231'],
        ['dialog 16/24 · 600', 'font-size: var(--rg-admin-text-workspace); line-height: 24px; font-weight: 600; letter-spacing: var(--rg-admin-tracking-ui)', 'Finalize removal?'],
        ['card 15/20 · 500', 'font-size: var(--rg-admin-text-ui); line-height: var(--rg-admin-leading-ui); font-weight: 500', 'Media storage is degraded'],
        ['body 14/22 · 400', 'font-size: var(--rg-admin-text-body); line-height: var(--rg-admin-leading-body)', 'Posts from new and regular users wait here before publishing.'],
        ['meta 13/18 · 400', 'font-size: var(--rg-admin-text-caption); line-height: var(--rg-admin-leading-caption); color: var(--rg-admin-text-tertiary)', 'P-48213 · Rabbits & rodents · waiting 3 h 12 min'],
        ['hint 12/16 · 400', 'font-size: var(--rg-admin-text-micro); line-height: var(--rg-admin-leading-micro); color: var(--rg-admin-text-tertiary)', 'Shared with the author and stored in the moderation log.'],
        ['overline 11/16', 'font-size: var(--rg-admin-text-overline); line-height: var(--rg-admin-leading-overline); font-weight: 500; letter-spacing: var(--rg-admin-tracking-overline); text-transform: uppercase; color: var(--rg-admin-text-tertiary)', 'Oldest waiting'],
        ['mono 12–13', 'font-family: var(--rg-admin-font-mono); font-size: var(--rg-admin-text-caption); color: var(--rg-admin-text-secondary)', 'rating_options.vibe.chaos_gremlin.label'],
    ];

    $radii = [['6 badge', 'var(--rg-admin-radius-badge)'], ['8 item', 'var(--rg-admin-radius-item)'], ['10 control', 'var(--rg-admin-radius-control)'], ['12 popover', 'var(--rg-admin-radius-popover)'], ['16 card', 'var(--rg-admin-radius-card)'], ['full pill', 'var(--rg-admin-radius-full)']];

    $icons = [
        'layout-grid', 'image', 'message-square', 'flag', 'users', 'user', 'folder', 'tag', 'star', 'globe', 'languages', 'settings-2',
        'hard-drive', 'search', 'search-x', 'check', 'check-check', 'minus', 'x', 'ellipsis', 'chevron-down', 'chevron-left',
        'chevron-right', 'chevrons-up-down', 'arrow-up-right', 'arrow-right', 'arrow-down', 'info', 'circle-alert', 'circle-check',
        'triangle-alert', 'lock', 'eye-off', 'ban', 'trash-2', 'refresh-cw', 'save', 'sparkles',
        'panel-left-open', 'menu', 'log-out', 'circle', 'ruler', 'text', 'braces',
    ];
@endphp

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FND-01']])
    <div class="rg-admin-kit__stack">
        <div class="rg-admin-kit__row">
            @foreach ($grays as [$name, $hex])
                <div class="rg-admin-kit__swatch">
                    <span class="rg-admin-kit__swatch-chip" style="background: var(--rg-admin-{{ $name }})"></span>
                    <span class="rg-admin-kit__swatch-name">{{ $name }}</span>
                    <span class="rg-admin-kit__swatch-hex">{{ $hex }}</span>
                </div>
            @endforeach
        </div>
        <div class="rg-admin-kit__grid">
            @foreach ($statuses as [$label, $tone, $vars])
                <div class="rg-admin-kit__status-swatch" style="background: var(--rg-admin-status-{{ $tone }}-bg); color: var(--rg-admin-status-{{ $tone }}-fg)">
                    <span class="rg-admin-kit__status-swatch-name">
                        <span class="rg-admin-kit__status-swatch-dot" style="background: var(--rg-admin-status-{{ $tone }}-dot)"></span>
                        {{ $label }}
                    </span>
                    <span class="rg-admin-kit__swatch-name">{{ $vars }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FND-02']])
    <div class="rg-admin-kit__stack">
        @foreach ($type as [$caption, $style, $sample])
            <div class="rg-admin-kit__labelled rg-admin-kit__labelled--wide">
                <span class="rg-admin-kit__caption">{{ $caption }}</span>
                <p class="rg-admin-kit__type-sample" style="{{ $style }}">{{ $sample }}</p>
            </div>
        @endforeach
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FND-03']])
    <div class="rg-admin-kit__row">
        <div class="rg-admin-kit__row">
            @foreach ($radii as [$caption, $radius])
                <div class="rg-admin-kit__item rg-admin-kit__item--center">
                    <span class="rg-admin-kit__shape" style="border-radius: {{ $radius }}"></span>
                    <span class="rg-admin-kit__caption">{{ $caption }}</span>
                </div>
            @endforeach
        </div>
        <div class="rg-admin-kit__row">
            <div class="rg-admin-kit__item rg-admin-kit__item--center">
                <span class="rg-admin-kit__elevation" style="box-shadow: var(--rg-admin-shadow-xs)"></span>
                <span class="rg-admin-kit__caption">shadow-xs</span>
            </div>
            <div class="rg-admin-kit__item rg-admin-kit__item--center">
                <span class="rg-admin-kit__elevation" style="border-color: transparent; background: var(--rg-admin-surface-inverse); box-shadow: var(--rg-admin-shadow-button-dark)"></span>
                <span class="rg-admin-kit__caption">button-dark</span>
            </div>
            <div class="rg-admin-kit__item rg-admin-kit__item--center">
                <span class="rg-admin-kit__elevation" style="border-radius: var(--rg-admin-radius-popover); box-shadow: var(--rg-admin-shadow-popover)"></span>
                <span class="rg-admin-kit__caption">popover</span>
            </div>
        </div>
        <div class="rg-admin-kit__row">
            @foreach ([1, 2, 3, 4, 5, 6, 7] as $step)
                <div class="rg-admin-kit__item rg-admin-kit__item--center">
                    <span class="rg-admin-kit__space" style="width: var(--rg-admin-space-{{ $step }}); height: var(--rg-admin-space-{{ $step }})"></span>
                    <span class="rg-admin-kit__caption">{{ $step * 4 }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FND-04']])
    <div class="rg-admin-kit__icon-grid">
        @foreach ($icons as $icon)
            <div class="rg-admin-kit__icon-cell">
                <x-admin.ui.icon :name="$icon" :size="18" />
                <span class="rg-admin-kit__icon-name">{{ $icon }}</span>
            </div>
        @endforeach
    </div>
@endcomponent
