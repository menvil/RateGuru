{{--
    A log row's visitor by its journey ID: for an administrator who may open
    Growth → Analytics, a plain link that opens its journey there — middle
    click and a new tab work as for any link — and the ID alone for anyone
    else. $journey as TrafficSourcesView::journey() gives it.
--}}
@if ($journey['url'] !== null)
    <a href="{{ $journey['url'] }}" class="rg-admin-link rg-admin-traffic__mono rg-admin-traffic__journey" title="Open this visitor’s journey in Analytics" data-testid="traffic-log-journey">{{ $journey['id'] }}</a>
@else
    <span class="rg-admin-traffic__mono rg-admin-traffic__journey">{{ $journey['id'] }}</span>
@endif
