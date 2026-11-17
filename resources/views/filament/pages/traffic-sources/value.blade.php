{{--
    Value: what an account is worth to the site and the country groups it is
    worth a share of — each saved as a new set of prices, so earlier visits
    keep theirs — then what the last working-out found: how likely a new
    visitor who reached each step is to become an account.
--}}
<div class="rg-admin-traffic__pair">
    <x-admin.ui.card title="What an account is worth" description="Visits from now on count by these; earlier ones keep the prices they came under.">
        <x-admin.ui.text-field label="A confirmed account ($)" id="rg-admin-price-account" :value="$prices['account_price']" :error="$errors->first('account_price') ?: null" wire:model="prices.account_price" />
        <x-admin.ui.text-field label="Its first post within 30 days (+$)" id="rg-admin-price-post" :value="$prices['post_bonus']" :error="$errors->first('post_bonus') ?: null" wire:model="prices.post_bonus" />
        <x-admin.ui.text-field label="Coming back on another day (+$)" id="rg-admin-price-return" :value="$prices['returned_bonus']" :error="$errors->first('returned_bonus') ?: null" wire:model="prices.returned_bonus" />
        <x-admin.ui.button variant="primary" wire:click="savePrices">Save prices</x-admin.ui.button>
    </x-admin.ui.card>

    <x-admin.ui.card title="Country groups" description="An account is worth its group’s share of the price. The last group holds every other country.">
        <div class="rg-admin-traffic__groups" role="group" aria-label="Country groups">
            <span class="rg-admin-traffic__groups-head">Name</span>
            <span class="rg-admin-traffic__groups-head">Share (%)</span>
            <span class="rg-admin-traffic__groups-head">Countries (US, GB…)</span>
            <span></span>
            @foreach ($groups as $index => $group)
                <input class="rg-admin-input" type="text" aria-label="Name of group {{ $index + 1 }}" wire:model="groups.{{ $index }}.name" wire:key="group-name-{{ $index }}" />
                <input class="rg-admin-input" type="text" inputmode="numeric" aria-label="Share of group {{ $index + 1 }}" wire:model="groups.{{ $index }}.percent" wire:key="group-percent-{{ $index }}" />
                @if ($index < count($groups) - 1)
                    <input class="rg-admin-input" type="text" aria-label="Countries of group {{ $index + 1 }}" wire:model="groups.{{ $index }}.countries" wire:key="group-countries-{{ $index }}" />
                    <x-admin.ui.icon-button icon="x" label="Remove group" variant="ghost" size="sm" wire:click="removeGroup({{ $index }})" />
                @else
                    <span class="rg-admin-traffic__note">Every other country</span>
                    <span></span>
                @endif
            @endforeach
        </div>
        @foreach ($errors->getMessages() as $key => $messages)
            @if (str_starts_with($key, 'groups'))
                <p class="rg-admin-traffic__check--danger">{{ implode(' ', $messages) }}</p>
            @endif
        @endforeach
        <div class="rg-admin-traffic__row">
            <x-admin.ui.button size="sm" icon="plus" wire:click="addGroup">Add group</x-admin.ui.button>
            <x-admin.ui.button variant="primary" wire:click="saveGroups">Save groups</x-admin.ui.button>
        </div>
    </x-admin.ui.card>
</div>

<x-admin.ui.card title="What a step is worth" :description="$run === null ? 'Not worked out yet: the chances need visits two weeks old or more. Until then every step is worth $0, or what you set by hand on a game’s Value tab.' : 'Worked out '.$run['at']?->diffForHumans().' from '.number_format($run['visits']).' matured visits and '.$run['accounts'].' accounts.'">
    @if ($chances !== [])
        <div class="rg-admin-table rg-admin-table__scroll">
            <div class="rg-admin-table__grid rg-admin-traffic__grid rg-admin-traffic__grid--4" role="table" aria-label="Chances">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    @foreach (['Step', 'Reached it', 'Became accounts', 'Chance'] as $heading)
                        <div class="rg-admin-table__cell" role="columnheader">{{ $heading }}</div>
                    @endforeach
                </div>
                @foreach ($chances as $chance)
                    <div class="rg-admin-table__row" role="row">
                        <div class="rg-admin-table__cell" role="cell">{{ $chance['label'] }}</div>
                        <div class="rg-admin-table__cell" role="cell">{{ number_format($chance['visitors']) }}</div>
                        <div class="rg-admin-table__cell" role="cell">{{ number_format($chance['accounts']) }}</div>
                        <div class="rg-admin-table__cell" role="cell">{{ number_format($chance['chance'] * 100, 2) }}%</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-admin.ui.card>
