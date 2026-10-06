<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\AdminNavigationGroup;
use App\Http\Middleware\SetAdminLocale;
use App\Livewire\Admin\Sidebar;
use App\Livewire\Admin\Topbar;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('RateGuru')
            // The site's own icon. Without one the browser falls back to
            // /favicon.ico, which is empty, and asks for it again on every
            // address change — each keystroke in a search that keeps its
            // query in the URL, each tab or page switch.
            ->favicon(fn (): string => asset('favicon.svg'))
            ->colors([
                'primary' => Color::Purple,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            // The Admin v2 shell: our own sidebar and top bar around Filament's
            // pages, which keep their content until each one is migrated.
            ->sidebarLivewireComponent(Sidebar::class)
            ->topbarLivewireComponent(Topbar::class)
            ->navigationGroups(AdminNavigationGroup::all())
            // Admin v2 is light only; a dark page inside a light shell, with
            // the theme switcher gone along with Filament's user menu, would
            // leave no way back.
            ->darkMode(false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                SetAdminLocale::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
