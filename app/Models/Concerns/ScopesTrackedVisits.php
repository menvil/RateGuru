<?php

namespace App\Models\Concerns;

use App\Enums\TrafficDimension;
use App\Support\Traffic\Reports\VisitFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * The visits the tracker counts and those a filter admits by device and
 * country, the same on the visits and on Analytics' own rows of them.
 */
trait ScopesTrackedVisits
{
    /**
     * The visits the tracker counts, as isTracked() tells them: every read
     * behind Traffic sources, a game's Analytics and the worth goes through it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTracked(Builder $query): Builder
    {
        $game = $query->qualifyColumn('game_id');
        $source = $query->qualifyColumn('source_id');

        return $query->where(fn (Builder $either) => $either->whereNotNull($game)->orWhereNotNull($source));
    }

    /**
     * The visits a filter admits by their device and country, the columns
     * named by TrafficDimension: the price set's last group takes a visit
     * whose country is not known.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMatching(Builder $query, VisitFilter $filter): Builder
    {
        $device = $query->qualifyColumn(TrafficDimension::Device->columns()[0]);
        $country = $query->qualifyColumn(TrafficDimension::Country->columns()[0]);
        $countries = $filter->countries;

        return $query
            ->when($filter->device !== null, fn (Builder $inner) => $inner->where($device, $filter->device))
            ->when($countries !== null && ! $filter->otherCountries, fn (Builder $inner) => $inner->whereIn($country, $countries ?? []))
            ->when($countries !== null && $filter->otherCountries, fn (Builder $inner) => $inner->where(fn (Builder $either) => $either->whereNull($country)->orWhereNotIn($country, $countries ?? [])));
    }
}
