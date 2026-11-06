<?php

namespace App\Livewire\Admin;

use App\Filament\Support\AdminShellNavigation;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Livewire\GlobalSearch as FilamentGlobalSearch;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Filament's global search, drawn as the Admin v2 sidebar search (FRM-01 in
 * NAV-01): the field under the workspace and a results list beneath it.
 *
 * The search itself stays Filament's: which resources are searched, which of
 * them the signed-in user may search, and the results. Only the field and the
 * list are Admin v2 markup. The ⌘K / Ctrl+K shortcut lives on the sidebar,
 * which can also open its drawer first when the sidebar is collapsed.
 */
final class GlobalSearch extends FilamentGlobalSearch
{
    public function render(): View
    {
        $results = $this->getResults();

        return view('livewire.admin.global-search', [
            'debounce' => filament()->getGlobalSearchDebounce(),
            'rows' => $results === null ? null : $this->rows($results->getCategories()),
        ]);
    }

    /**
     * One row per result, in Filament's order, each with what it is.
     *
     * @param  Collection<string, iterable<GlobalSearchResult>>  $categories
     * @return list<array{title: string, url: string, meta: string, icon: string}>
     */
    private function rows(Collection $categories): array
    {
        $rows = [];

        foreach ($categories as $category => $results) {
            $kind = AdminShellNavigation::searchCategory((string) $category);

            foreach ($results as $result) {
                $rows[] = [
                    'title' => strip_tags($result->title instanceof Htmlable ? $result->title->toHtml() : $result->title),
                    'url' => $result->url,
                    'meta' => collect([$kind['type'], ...array_values($result->details)])
                        ->map(fn (mixed $part): string => strip_tags((string) $part))
                        ->filter()
                        ->implode(' · '),
                    'icon' => $kind['icon'],
                ];
            }
        }

        return $rows;
    }
}
