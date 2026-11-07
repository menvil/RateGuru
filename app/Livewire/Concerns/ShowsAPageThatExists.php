<?php

namespace App\Livewire\Concerns;

use Closure;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * For a component that uses WithPagination: a page past the last one — an old
 * link, or a list that shrank while it was open — shows the last page instead
 * of an empty list that reads as "nothing here".
 */
trait ShowsAPageThatExists
{
    /**
     * @template TValue
     *
     * @param  Closure(): LengthAwarePaginator<int, TValue>  $query
     * @return LengthAwarePaginator<int, TValue>
     */
    protected function pageThatExists(Closure $query): LengthAwarePaginator
    {
        $page = $query();

        if ($page->isEmpty() && $page->currentPage() > $page->lastPage()) {
            $this->setPage($page->lastPage(), $page->getPageName());

            $page = $query();
        }

        return $page;
    }
}
