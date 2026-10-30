<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Personas\Pages\ListPersonas;
use App\Filament\Resources\Personas\PersonaResource;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\RatingGroups\Pages\CreateRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\ManageRatingGroups;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\ListStudioPlans;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Http\Middleware\SetAdminLocale;
use App\Livewire\Admin\Sidebar;
use App\Livewire\Admin\Topbar;
use App\Support\Settings\ProjectSettingsManager;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
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
            // The project's own name from its settings, as the public site shows
            // it — the browser tab, the sign-in page and the sidebar all read it
            // from here.
            ->brandName(fn (): string => app(ProjectSettingsManager::class)->current()->siteName())
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
            // A migrated page's own action, on the right of the top bar.
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => self::createAction(CategoryResource::canCreate(), 'Create category', 'create-category'),
                scopes: ManageCategories::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => self::createAction(TagResource::canCreate(), 'Create tag', 'create-tag'),
                scopes: ManageTags::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => RatingGroupResource::canCreate() ? view('filament.pages.rating-groups.topbar')->render() : '',
                scopes: ManageRatingGroups::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => self::editorActions('Create group', 'Unsaved group changes', 'discard-rating-group', 'save-rating-group'),
                scopes: CreateRatingGroup::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => self::editorActions('Save group', 'Unsaved group changes', 'discard-rating-group', 'save-rating-group'),
                scopes: EditRatingGroup::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => self::editorActions('Save changes', null, 'discard-project-settings', 'save-project-settings'),
                scopes: ProjectSettingsPage::class,
            )
            // A Persona is made and edited in the drawer over the list.
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => self::createAction(PersonaResource::canCreate(), 'Create persona', 'create-persona'),
                scopes: ListPersonas::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => StudioPlanResource::canCreate() ? view('filament.pages.studio-plans.topbar')->render() : '',
                scopes: ListStudioPlans::class,
            )
            // A new publication may be created empty, so Create draft does
            // not wait for a change the way Save does on other editors.
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => self::editorActions('Create draft', null, 'discard-studio-plan', 'save-studio-plan', savesUnchanged: true),
                scopes: CreateStudioPlan::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => self::editorActions('Save draft', null, 'discard-studio-plan', 'save-studio-plan'),
                scopes: EditStudioPlan::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => view('filament.pages.posts.topbar', ['at' => now()->format('H:i')])->render(),
                scopes: ListPosts::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => view('filament.pages.reports.topbar')->render(),
                scopes: ListReports::class,
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => view('filament.pages.media-diagnostics.topbar', ['at' => now()->format('H:i')])->render(),
                scopes: MediaDiagnosticsPage::class,
            )
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

    /**
     * The primary action of a page drawn in Admin v2, in the top bar: it asks
     * the page, by a browser event, to open what it creates. Nothing for a
     * user who may not create.
     */
    private static function createAction(bool $allowed, string $label, string $event): string
    {
        return $allowed
            ? view('filament.pages.taxonomy.create-action', ['label' => $label, 'event' => $event])->render()
            : '';
    }

    /**
     * An editor page's top bar: its unsaved state, Discard and Save, which
     * dispatch these events for the page to handle. Save waits for a change
     * unless the page saves an unchanged draft too.
     */
    private static function editorActions(string $label, ?string $unsaved, string $discard, string $save, bool $savesUnchanged = false): string
    {
        return view('filament.pages.editor.topbar', ['label' => $label, 'unsaved' => $unsaved, 'discard' => $discard, 'save' => $save, 'savesUnchanged' => $savesUnchanged])->render();
    }
}
