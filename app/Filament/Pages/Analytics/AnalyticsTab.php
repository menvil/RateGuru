<?php

namespace App\Filament\Pages\Analytics;

use App\Filament\Pages\GrowthAnalyticsPage;
use App\Support\Analytics\AnalyticsFilter;

/**
 * A tab of Growth → Analytics: what its view (filament.pages.analytics.{tab})
 * draws, read for the page's filter only while the tab is open. Each tab
 * says itself when nobody matches the filter.
 */
interface AnalyticsTab
{
    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array;
}
