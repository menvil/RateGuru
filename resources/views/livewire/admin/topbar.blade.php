@php
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;
@endphp

{{--
    The Admin v2 top bar: where the user is on the left, page context on the
    right. Pages keep their own Filament headings and actions until each one
    is migrated; the TOPBAR_END hook is where a migrated page puts its actions.
--}}
<div class="rg-admin rg-admin-shell-topbar">
    <header class="rg-admin-topbar">
        <x-admin.ui.icon-button
            icon="menu"
            label="Open navigation"
            variant="ghost"
            class="rg-admin-topbar__menu"
            aria-controls="rg-admin-sidebar"
            aria-expanded="false"
            x-data="{ expanded: false }"
            x-on:rg-admin-nav-state.window="expanded = $event.detail.open"
            x-bind:aria-expanded="expanded"
            x-on:click="$dispatch('rg-admin-nav-open', { trigger: $el })"
        />

        @if ($breadcrumb !== [])
            <nav class="rg-admin-breadcrumb" aria-label="Breadcrumb">
                <ol class="rg-admin-breadcrumb__list">
                    @foreach ($breadcrumb as $crumb)
                        <li class="rg-admin-breadcrumb__item">
                            @unless ($loop->first)
                                <x-admin.ui.icon name="chevron-right" :size="14" class="rg-admin-breadcrumb__separator" />
                            @endunless
                            @if ($crumb['current'])
                                <span class="rg-admin-breadcrumb__current" aria-current="page">{{ $crumb['label'] }}</span>
                            @elseif ($crumb['url'] !== null)
                                <a class="rg-admin-breadcrumb__link" href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                            @else
                                <span class="rg-admin-breadcrumb__section">{{ $crumb['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif

        <div class="rg-admin-topbar__actions">
            {{ FilamentView::renderHook(PanelsRenderHook::TOPBAR_END, scopes: $renderHookScopes) }}
        </div>
    </header>
</div>
