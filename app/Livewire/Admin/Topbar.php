<?php

namespace App\Livewire\Admin;

use App\Filament\Support\AdminShellNavigation;
use Filament\Enums\GlobalSearchPosition;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

use function Filament\Support\original_request;

/**
 * The Admin v2 top bar, registered with the panel's topbarLivewireComponent().
 *
 * Breadcrumb on the left, built from the navigation without loading any record;
 * on the right Filament's own global search, which the admin already relied
 * on, and the TOPBAR_END render hook, scoped to the current page, where a page
 * migrated to Admin v2 can put its actions. Below 1024px it also carries the
 * button that opens the navigation drawer.
 */
final class Topbar extends Component
{
    #[On('refresh-topbar')]
    public function refresh(): void {}

    public function render(): View
    {
        return view('livewire.admin.topbar', [
            'breadcrumb' => AdminShellNavigation::breadcrumb(
                AdminShellNavigation::sections(),
                original_request()->url(),
            ),
            'hasGlobalSearch' => filament()->isGlobalSearchEnabled()
                && filament()->getGlobalSearchPosition() === GlobalSearchPosition::Topbar,
            'renderHookScopes' => $this->pageScopes(),
        ]);
    }

    /**
     * The page being shown, and its resource, as render hook scopes.
     *
     * @return list<class-string>
     */
    private function pageScopes(): array
    {
        $page = original_request()->route()?->getControllerClass();

        if ($page === null || ! class_exists($page)) {
            return [];
        }

        return is_subclass_of($page, ResourcePage::class)
            ? [$page, $page::getResource()]
            : [$page];
    }
}
