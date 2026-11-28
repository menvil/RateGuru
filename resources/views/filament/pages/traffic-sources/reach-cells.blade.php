{{--
    A row's two figures of reach in Sites & zones: the impressions its
    network billed for it, and the share of them that reached RateGuru with
    how many visits that was; a dash where the network reported none for it.
--}}
@if ($r === null)
    <span class="rg-admin-traffic__fig" role="gridcell">—</span>
    <span class="rg-admin-traffic__fig" role="gridcell">—</span>
@else
    <span class="rg-admin-traffic__fig" role="gridcell"><strong>{{ number_format($r->impressions) }}</strong></span>
    <span class="rg-admin-traffic__fig" role="gridcell"><strong>{{ $r->reachRate() === null ? '—' : number_format($r->reachRate() * 100, 2).'%' }}</strong><small>{{ number_format($r->reached) }} {{ $r->reached === 1 ? 'visit' : 'visits' }}</small></span>
@endif
