<?php

namespace App\Filament\Support;

use Illuminate\Http\Request;

/**
 * A page that opens one of its items at its own address with something in
 * the query, rather than on a page of its own — Growth → Experiments opens a
 * period's results at `?period=`. While it shows one, the top bar's
 * breadcrumb leads back to the page as an edit page's leads back to its list.
 */
interface ShowsItemsInPlace
{
    /** Whether the request opens one of the page's items. */
    public static function showsItem(Request $request): bool;
}
