{{--
    A row's twelve figures in Performance, each with the line under it the
    reference gives: visits; opened, started and finished, each with its
    share of the step before; accounts with how many posted, or their share
    of the finished; spend with what is still an estimate; value with what is
    still expected; profit, ROI, and spend, value and cost per visit and per
    account.
--}}
@php
    $share = fn (int $part, int $whole): string => $whole === 0 ? '—' : number_format($part * 100 / $whole, 1).'%';
    $spendNow = \App\Support\Traffic\Money\TrafficMoney::of($f->spend);
    // Profit to the cent: a fraction of a cent either way reads as none, not as −$0.00.
    $profit = $f->value()->minus($spendNow)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
    $roi = $f->roi();
@endphp
<span class="rg-admin-traffic__fig" role="gridcell"><strong>{{ number_format($f->visits) }}</strong></span>
<span class="rg-admin-traffic__fig" role="gridcell"><strong>{{ number_format($f->opened) }}</strong><small>{{ $share($f->opened, $f->visits) }}</small></span>
<span class="rg-admin-traffic__fig" role="gridcell"><strong>{{ number_format($f->started) }}</strong><small>{{ $share($f->started, $f->opened) }}</small></span>
<span class="rg-admin-traffic__fig" role="gridcell"><strong>{{ number_format($f->completed) }}</strong><small>{{ $share($f->completed, $f->started) }}</small></span>
<span class="rg-admin-traffic__fig" role="gridcell"><strong>{{ number_format($f->accounts) }}</strong><small>{{ $f->posted > 0 ? number_format($f->posted).' posted' : $share($f->accounts, $f->completed).' of finished' }}</small></span>
<span class="rg-admin-traffic__fig" role="gridcell"><strong>{{ $money($f->spend) }}</strong>@if (\App\Support\Traffic\Money\TrafficMoney::of($f->spendEstimated)->isPositive())<small>incl. {{ $money($f->spendEstimated) }} est.</small>@endif</span>
<span class="rg-admin-traffic__fig" role="gridcell"><strong>{{ $money($f->value()) }}</strong>@if (\App\Support\Traffic\Money\TrafficMoney::of($f->valueExpected)->isPositive())<small>{{ $money($f->valueExpected) }} expected</small>@endif</span>
<span @class(['rg-admin-traffic__fig', 'rg-admin-traffic__good' => $spendNow->isPositive() && $profit->isPositive(), 'rg-admin-traffic__bad' => $profit->isNegative()]) role="gridcell"><strong>{{ $spendNow->isZero() ? '—' : ($profit->isNegative() ? '−' : '').$money($profit->abs()) }}</strong></span>
<span @class(['rg-admin-traffic__fig', 'rg-admin-traffic__good' => ($roi ?? 0) > 0, 'rg-admin-traffic__bad' => ($roi ?? 0) < 0]) role="gridcell"><strong>{{ $roi === null ? '—' : ($roi > 0 ? '+' : '').number_format($roi, 0).'%' }}</strong></span>
<span class="rg-admin-traffic__fig" role="gridcell">{{ $f->visits === 0 ? '—' : $money((float) (string) $spendNow / $f->visits, 4) }}</span>
<span class="rg-admin-traffic__fig" role="gridcell">{{ $f->visits === 0 ? '—' : $money((float) (string) $f->value() / $f->visits, 4) }}</span>
<span class="rg-admin-traffic__fig" role="gridcell">{{ $f->costPerAccount() === null ? '—' : $money($f->costPerAccount()) }}</span>
