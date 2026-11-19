{{-- The days of Analytics' period, in the top bar as the reference has them; a period chosen later moves them (rg-admin-analytics-range, sent by GrowthAnalyticsPage). --}}
<span
    class="rg-admin-topbar__meta rg-admin-analytics__range"
    data-testid="analytics-range"
    x-data="{ text: @js($range) }"
    x-text="text"
    x-on:rg-admin-analytics-range.window="text = $event.detail.text"
>{{ $range }}</span>
