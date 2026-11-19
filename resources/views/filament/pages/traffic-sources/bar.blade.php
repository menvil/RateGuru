{{--
    One line of How accounts come or On the way here: its name, count and
    share, a bar of that share, and what it counts. A line with nothing to be
    a share of — the first of a way — shows a full bar and no share.
--}}
@php($ratio = $of === null ? 1 : ($of > 0 ? min(1, $count / $of) : 0))
<div class="rg-admin-traffic__bar" @isset($testid) data-testid="{{ $testid }}" @endisset>
    <span class="rg-admin-traffic__bar-head"><span>{{ $name }}</span><strong>{{ number_format($count) }}</strong><span class="rg-admin-traffic__note">{{ $of === null ? '' : ($of > 0 ? ($ratio * 100 >= 10 ? number_format($ratio * 100, 0) : number_format($ratio * 100, 1)).'%' : '—') }}</span></span>
    <span class="rg-admin-traffic__bar-track"><span @class(['rg-admin-traffic__bar-fill', 'rg-admin-traffic__bar-fill--good' => $good]) style="width: {{ round($ratio * 100, 1) }}%"></span></span>
    @if ($note)
        <span class="rg-admin-traffic__note">{{ $note }}</span>
    @endif
</div>
