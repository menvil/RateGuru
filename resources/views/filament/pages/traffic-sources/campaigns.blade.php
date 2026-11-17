{{--
    Campaigns: the networks' campaigns RateGuru has seen clicks of — new ones
    first, came in by themselves and waiting to be set up — with their
    figures for the period; the link to paste into a source's campaigns; and
    a pasted link read the way the server reads a click.
--}}
<div class="rg-admin-traffic__pair">
    <x-admin.ui.card title="Get the link" description="Choose a source above and a game: paste the link as the target URL of every campaign of that source.">
        <label class="rg-admin-field">
            <span class="rg-admin-label">Game</span>
            <select class="rg-admin-traffic__select" wire:model.live="linkGame">
                @foreach ($games as $id => $title)
                    <option value="{{ $id }}">{{ $title }}</option>
                @endforeach
            </select>
        </label>
        @if ($link !== null)
            <p class="rg-admin-traffic__mono rg-admin-traffic__link" data-testid="traffic-link">{{ $link }}</p>
            <x-admin.ui.button size="sm" icon="copy" x-data x-on:click="navigator.clipboard?.writeText(@js($link)).then(() => $dispatch('rg-admin-toast', { message: 'Link copied', tone: 'success' }))">Copy link</x-admin.ui.button>
        @else
            <p class="rg-admin-traffic__note">Choose a source in the filter above to get its link.</p>
        @endif
    </x-admin.ui.card>

    <x-admin.ui.card title="Check a link" description="Paste a link to paste, or a real click from the access log.">
        <x-admin.ui.text-field label="Link" id="rg-admin-traffic-check" :value="$checkLink" wire:model.live.debounce.500ms="checkLink" />
        @if ($inspection !== null)
            <div data-testid="traffic-link-check">
                <x-admin.ui.badge :tone="match ($inspection->mode) { 'click' => 'success', 'template' => 'outline', 'mixed' => 'warning', default => 'danger' }">{{ ['template' => 'Link to paste', 'click' => 'A real click', 'mixed' => 'Tokens left', 'none' => 'Unpaid', 'bad' => 'Not a link'][$inspection->mode] }}</x-admin.ui.badge>
                <ul class="rg-admin-traffic__checks">
                    @foreach ($inspection->fields as $field)
                        <li><span class="rg-admin-traffic__mono">{{ $field['param'] }}</span> {{ $field['label'] }}: {{ $field['value'] }} <span class="rg-admin-traffic__note">({{ $field['state'] }})</span></li>
                    @endforeach
                </ul>
                <ul class="rg-admin-traffic__checks">
                    @foreach ($inspection->warnings as $warning)
                        <li class="rg-admin-traffic__check--{{ $warning['tone'] }}">{{ $warning['text'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-admin.ui.card>
</div>

@if ($campaigns === [])
    <x-admin.ui.empty-state icon="megaphone" title="No campaigns yet">A campaign shows here by itself with the first click that names it.</x-admin.ui.empty-state>
@else
    <div class="rg-admin-table__scroll">
        <div class="rg-admin-table__grid rg-admin-traffic__grid" role="table" aria-label="Campaigns">
            <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                @foreach (['Campaign', 'Source', 'Stage', 'Visits', 'Accounts', 'Spend', 'Value', 'ROI', 'Cost of an account'] as $heading)
                    <div class="rg-admin-table__cell" role="columnheader">{{ $heading }}</div>
                @endforeach
            </div>
            @foreach ($campaigns as $row)
                <div class="rg-admin-table__row" role="row" wire:key="campaign-{{ $row['id'] }}">
                    <div class="rg-admin-table__cell" role="cell"><button type="button" class="rg-admin-traffic__drill" wire:click="openCampaign({{ $row['id'] }})">{{ $row['name'] }}</button></div>
                    <div class="rg-admin-table__cell" role="cell">{{ $row['source'] }}</div>
                    <div class="rg-admin-table__cell" role="cell">
                        <x-admin.ui.badge :tone="$row['status'] === 'new' ? 'warning' : ($row['status'] === 'archived' ? 'neutral' : 'outline')">{{ $row['status'] === 'new' ? 'New' : ($row['status'] === 'archived' ? 'Archived' : ucfirst((string) $row['stage'])) }}</x-admin.ui.badge>
                    </div>
                    <div class="rg-admin-table__cell" role="cell">{{ number_format($row['figures']->visits) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ number_format($row['figures']->accounts) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $money($row['figures']->spend) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $money($row['figures']->value()) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $row['figures']->roi() === null ? '—' : number_format($row['figures']->roi(), 0).'%' }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $row['figures']->costPerAccount() === null ? '—' : $money($row['figures']->costPerAccount()) }}</div>
                </div>
            @endforeach
        </div>
    </div>
@endif
