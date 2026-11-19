{{-- One line of How accounts come: its name, count and share, a bar of that share, and what it counts. --}}
@php($ratio = $of > 0 ? min(1, $count / $of) : 0)
<div class="rg-admin-traffic__bar">
    <span class="rg-admin-traffic__bar-head"><span>{{ $name }}</span><strong>{{ number_format($count) }}</strong><span class="rg-admin-traffic__note">{{ $of > 0 ? ($ratio * 100 >= 10 ? number_format($ratio * 100, 0) : number_format($ratio * 100, 1)).'%' : '—' }}</span></span>
    <span class="rg-admin-traffic__bar-track"><span @class(['rg-admin-traffic__bar-fill', 'rg-admin-traffic__bar-fill--good' => $good]) style="width: {{ round($ratio * 100, 1) }}%"></span></span>
    @if ($note)
        <span class="rg-admin-traffic__note">{{ $note }}</span>
    @endif
</div>
