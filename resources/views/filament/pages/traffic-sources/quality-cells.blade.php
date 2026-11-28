{{--
    A Sites & zones row's two signs of visits that are not people: the share
    with any bot flag, its flags with their counts on hover; and the share
    from an address the same place sent another visit from, of those whose
    address is still on record.
--}}
@php
    $flagRate = $f->flaggedRate();
    $repeatRate = $f->repeatAddressRate();
    $flagTip = implode(', ', array_map(fn (string $word, int $count): string => $word.' '.number_format($count), array_keys($f->flags()), $f->flags()));
@endphp
<span class="rg-admin-traffic__fig" role="gridcell" @if ($flagTip !== '') title="{{ $flagTip }}" @endif data-testid="traffic-site-flagged">
    <strong>{{ $flagRate === null ? '—' : number_format($flagRate * 100, 1).'%' }}</strong>@if ($f->flagged > 0)<small>{{ number_format($f->flagged) }} {{ $f->flagged === 1 ? 'visit' : 'visits' }}</small>@endif
    @if ($flagTip !== '')<span class="rg-admin-sr-only">, {{ $flagTip }}</span>@endif
</span>
<span class="rg-admin-traffic__fig" role="gridcell" data-testid="traffic-site-repeat">
    <strong>{{ $repeatRate === null ? '—' : number_format($repeatRate * 100, 1).'%' }}</strong>@if ($f->addressed > 0)<small>of {{ number_format($f->addressed) }}</small>@endif
</span>
