{{--
    Sign-ups and confirmation: every sign-up of the visits on the filters,
    paid and unpaid, split by the dimension chosen — kept in the address — the
    ten with the most and the rest in one row: how many signed up and
    confirmed, how many never confirmed or are still inside the window to,
    and how long after signing up the confirmed ones confirmed; then the
    total and, when unpaid sign-ups are among them, the paid part How
    accounts come counts.
--}}
@php
    $card = $signups;
    $columns = ['Signed up', 'Confirmed', 'Confirm rate', 'Never confirmed', 'Still waiting', 'Confirmed within 10 min', 'Confirmed in 10–60 min', 'Confirmed in 1–24 hours', 'Confirmed later'];
@endphp

<section class="rg-admin-traffic__panel" data-testid="traffic-signups">
    <header class="rg-admin-traffic__perf-head">
        <div>
            <h2 class="rg-admin-traffic__panel-title">Sign-ups and confirmation</h2>
            <p class="rg-admin-traffic__note">Every sign-up of the visits on these filters, paid and unpaid, and whether its account was confirmed. Google and Facebook confirm at once. An email sign-up has {{ $graceDays }} days to confirm: until then it is still waiting; after them it is removed with everything it did and never counted — never confirmed. The last four columns: how long after signing up the confirmed ones confirmed, not the time from the click that How accounts come shows.</p>
        </div>
        <div class="rg-admin-traffic__views" role="radiogroup" aria-label="Sign-ups by">
            @foreach ($card['dimensions'] as $key => $name)
                <button type="button" role="radio" aria-checked="{{ $card['by'] === $key ? 'true' : 'false' }}" @class(['rg-admin-traffic__view', 'rg-admin-traffic__view--on' => $card['by'] === $key]) wire:click="$set('signupsBy', '{{ $key }}')">{{ $name }}</button>
            @endforeach
        </div>
    </header>

    @if ($card['total']->signedUp === 0)
        <x-admin.ui.empty-state icon="user-plus" title="No sign-ups on these filters">Sign-ups show here once a visitor recorded in this period signs up, with email, Google or Facebook.</x-admin.ui.empty-state>
    @else
        <div class="rg-admin-traffic__perf rg-admin-table__scroll">
            <div class="rg-admin-traffic__perf-grid rg-admin-traffic__perf-grid--signups" role="table" aria-label="Sign-ups by {{ strtolower($card['heading']) }}">
                <div class="rg-admin-traffic__perf-row rg-admin-traffic__perf-row--head" role="row">
                    <span role="columnheader">{{ $card['heading'] }}</span>
                    @foreach ($columns as $heading)
                        <span role="columnheader">{{ $heading }}</span>
                    @endforeach
                </div>
                @foreach ($card['rows'] as $row)
                    <div class="rg-admin-traffic__perf-row" role="row" wire:key="signups-{{ $row['key'] }}">
                        <span class="rg-admin-traffic__perf-name" role="cell">
                            <span class="rg-admin-traffic__perf-label">
                                <span @class(['rg-admin-traffic__perf-title', 'rg-admin-traffic__mono' => $row['mono']])>{{ $row['label'] }}</span>
                                @if ($row['sub'] !== null)
                                    <span class="rg-admin-traffic__note">{{ $row['sub'] }}</span>
                                @endif
                                @if ($row['find'] !== null)
                                    <button type="button" class="rg-admin-traffic__text-button" wire:click="listSites({{ $row['find']['source'] }}, '{{ $row['find']['level'] }}', @js($row['find']['ref']))">Find in Sites &amp; zones</button>
                                @endif
                            </span>
                        </span>
                        @include('filament.pages.traffic-sources.signup-cells', ['f' => $row['figures']])
                    </div>
                @endforeach
                @if ($card['rest'] !== null)
                    <div class="rg-admin-traffic__perf-row" role="row">
                        <span class="rg-admin-traffic__perf-name" role="cell"><span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__note">+ {{ number_format($card['rest']['count']) }} more {{ $card['noun'] }}</span></span></span>
                        @include('filament.pages.traffic-sources.signup-cells', ['f' => $card['rest']['figures']])
                    </div>
                @endif
                <div class="rg-admin-traffic__perf-row rg-admin-traffic__perf-row--total" role="row">
                    <span class="rg-admin-traffic__perf-name" role="cell"><span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__perf-title">Total</span><span class="rg-admin-traffic__note">These filters</span></span></span>
                    @include('filament.pages.traffic-sources.signup-cells', ['f' => $card['total']])
                </div>
                @if ($card['paid'] !== null)
                    <div class="rg-admin-traffic__perf-row rg-admin-traffic__perf-row--part" role="row" data-testid="traffic-signups-paid">
                        <span class="rg-admin-traffic__perf-name" role="cell"><span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__perf-title">Of it paid traffic</span><span class="rg-admin-traffic__note">What How accounts come counts</span></span></span>
                        @include('filament.pages.traffic-sources.signup-cells', ['f' => $card['paid']])
                    </div>
                @endif
            </div>
        </div>
        <p class="rg-admin-traffic__note">Many email sign-ups with few confirmations from one zone or one country usually means bots signing up. Split by Zone or Country to find them; a network’s zone opens in Sites &amp; zones below, where it is blocked.</p>
    @endif
</section>
