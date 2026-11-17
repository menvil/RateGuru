{{--
    Spend: what each source cost in the period, and the spend no visit took;
    for the source chosen above, each of its days — clicks, what their links
    said they cost, the day's figures by origin and which one counts — with a
    day's total typed in, or a file of the network's figures.
--}}
<div class="rg-admin-table rg-admin-table__scroll">
    <div class="rg-admin-table__grid rg-admin-traffic__grid rg-admin-traffic__grid--5" role="table" aria-label="Spend by source">
        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
            @foreach (['Source', 'Visits', 'Spend on visits', 'Spend without visits', 'An estimate'] as $heading)
                <div class="rg-admin-table__cell" role="columnheader">{{ $heading }}</div>
            @endforeach
        </div>
        @forelse ($bySource as $row)
            <div class="rg-admin-table__row" role="row">
                <div class="rg-admin-table__cell" role="cell">{{ $labels['source_id'][(string) $row['keys']['source_id']] ?? 'Source #'.$row['keys']['source_id'] }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ number_format($row['figures']->visits) }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ $money($row['figures']->spend) }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ $money($row['unallocated']) }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ $money($row['figures']->spendEstimated) }}</div>
            </div>
        @empty
            <div class="rg-admin-table__row" role="row"><div class="rg-admin-table__cell" role="cell">No paid visits in this period.</div></div>
        @endforelse
    </div>
</div>

@if ($days === null)
    <p class="rg-admin-traffic__note">Choose a source above to see its days and enter its spend.</p>
@else
    <div class="rg-admin-traffic__pair">
        <x-admin.ui.card title="Enter a day’s spend" description="A typed total counts before a file, a file before the network’s API. Leave the amount empty to remove one.">
            <x-admin.ui.text-field label="Day (the network’s)" id="rg-admin-spend-day" type="date" :value="$spendEntry['day']" :error="$errors->first('day') ?: null" wire:model="spendEntry.day" />
            <x-admin.ui.text-field label="Amount ($)" id="rg-admin-spend-amount" :value="$spendEntry['amount']" :error="$errors->first('amount') ?: null" wire:model="spendEntry.amount" />
            <x-admin.ui.button variant="primary" wire:click="enterSpend">Save the day</x-admin.ui.button>
        </x-admin.ui.card>
        <x-admin.ui.card title="Import a file" description="CSV with day and amount, and if wanted campaign, site, zone, clicks and impressions. A mistake refuses the whole file.">
            <input type="file" accept=".csv,text/csv" wire:model="spendFile" aria-label="Spend file" />
            @error('file')
                <ul class="rg-admin-traffic__checks">@foreach ($errors->get('file') as $message)<li class="rg-admin-traffic__check--danger">{{ is_array($message) ? implode(' ', $message) : $message }}</li>@endforeach</ul>
            @enderror
            <x-admin.ui.button wire:click="importSpend">Import</x-admin.ui.button>
        </x-admin.ui.card>
    </div>

    <div class="rg-admin-table rg-admin-table__scroll">
        <div class="rg-admin-table__grid rg-admin-traffic__grid rg-admin-traffic__grid--6" role="table" aria-label="Day by day">
            <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                @foreach (['Day', 'Clicks', 'Their links said', 'Figures', 'Counted on visits', 'State'] as $heading)
                    <div class="rg-admin-table__cell" role="columnheader">{{ $heading }}</div>
                @endforeach
            </div>
            @forelse ($days as $day => $row)
                <div class="rg-admin-table__row" role="row">
                    <div class="rg-admin-table__cell" role="cell">{{ $day }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ number_format($row['clicks']) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $money($row['costs'], 4) }}</div>
                    <div class="rg-admin-table__cell" role="cell">
                        @forelse ($row['figures'] as $origin => $amount)
                            <span @class(['rg-admin-traffic__counted' => $origin === $row['counted_origin']])>{{ $origin }} {{ $money($amount) }}</span>
                        @empty
                            —
                        @endforelse
                    </div>
                    <div class="rg-admin-table__cell" role="cell">{{ $money($row['counted']) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $row['figures'] === [] ? 'estimate' : ($row['allocated'] ? 'counted' : 'sharing out') }}</div>
                </div>
            @empty
                <div class="rg-admin-table__row" role="row"><div class="rg-admin-table__cell" role="cell">No days in this period.</div></div>
            @endforelse
        </div>
    </div>
@endif
