{{--
    A row's nine figures in Sign-ups and confirmation: signed up, confirmed,
    the share confirmed; never confirmed with its share of those who signed
    up, still waiting; and how long after signing up the confirmed ones
    confirmed, each time with its share of the confirmed.
--}}
@php
    $share = fn (int $part, int $whole): string => $whole === 0 ? '—' : number_format($part * 100 / $whole, 1).'%';
@endphp
<span class="rg-admin-traffic__fig" role="cell"><strong>{{ number_format($f->signedUp) }}</strong></span>
<span class="rg-admin-traffic__fig" role="cell"><strong>{{ number_format($f->confirmed) }}</strong></span>
<span class="rg-admin-traffic__fig" role="cell"><strong>{{ $f->confirmRate() === null ? '—' : number_format($f->confirmRate() * 100, 1).'%' }}</strong></span>
<span class="rg-admin-traffic__fig" role="cell"><strong>{{ number_format($f->neverConfirmed) }}</strong><small>{{ $share($f->neverConfirmed, $f->signedUp) }}</small></span>
<span class="rg-admin-traffic__fig" role="cell"><strong>{{ number_format($f->waiting) }}</strong></span>
@foreach ([$f->confirmedIn10Minutes, $f->confirmedInAnHour, $f->confirmedInADay, $f->confirmedLater()] as $count)
    <span class="rg-admin-traffic__fig" role="cell"><strong>{{ number_format($count) }}</strong><small>{{ $share($count, $f->confirmed) }}</small></span>
@endforeach
