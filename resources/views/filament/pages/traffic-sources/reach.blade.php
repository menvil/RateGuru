{{--
    On the way here, under the report's tiles: the impressions the ad
    networks billed on the filters, how many of them reached RateGuru,
    opened the page and started the game — each step's share of the one
    before, on a bar — then the impressions paid for that never arrived with
    what they cost by estimate, and the days whose figures can still change.
    Narrowed to a device, a country or a game, only the networks' visits
    show: their impressions aren't split that way.
--}}
<section class="rg-admin-traffic__panel" data-testid="traffic-reach">
    <header class="rg-admin-traffic__panel-head">
        <h2 class="rg-admin-traffic__panel-title">On the way here</h2>
        <p class="rg-admin-traffic__note">
            @if ($reach['split'])
                The network’s impressions aren’t split by device, country or game, so they’re left out on these filters.
            @else
                What ad networks billing by the impression charged for on these filters, against the visits of the same days, campaigns and sites. Some paid impressions never arrive: the window closed before the page loaded, the browser blocked it, the connection broke, or a bot never loaded it.
            @endif
        </p>
    </header>
    <div class="rg-admin-traffic__reach">
        @foreach ($reach['steps'] as $step)
            @include('filament.pages.traffic-sources.bar', ['name' => $step['name'], 'count' => $step['count'], 'of' => $step['of'], 'note' => $step['note'], 'good' => false, 'testid' => 'traffic-reach-step'])
        @endforeach
    </div>
    @if ($reach['lost'] !== null)
        <p class="rg-admin-traffic__reach-lost" data-testid="traffic-reach-lost">{{ $reach['lost'] }}</p>
        <p class="rg-admin-traffic__note">The cost is an estimate: the network’s amount shared evenly over its impressions. A day’s visits are counted for the campaigns and sites the network reported that day; visits of the rest count on neither side.</p>
    @endif
    @if ($reach['open'] !== null)
        <p class="rg-admin-traffic__note" data-testid="traffic-reach-open">{{ $reach['open'] }}</p>
    @endif
</section>
