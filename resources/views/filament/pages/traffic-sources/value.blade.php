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
        @foreach ($groups as $index => $group)
            <div class="rg-admin-traffic__row" wire:key="group-{{ $index }}">
                <x-admin.ui.text-field label="Name" :id="'rg-admin-group-name-'.$index" :value="$group['name']" :error="$errors->first('groups.'.$index.'.name') ?: null" wire:model="groups.{{ $index }}.name" />
                <x-admin.ui.text-field label="Share (%)" :id="'rg-admin-group-percent-'.$index" :value="(string) $group['percent']" wire:model="groups.{{ $index }}.percent" />
                @if ($index < count($groups) - 1)
                    <x-admin.ui.text-field label="Countries (US, GB…)" :id="'rg-admin-group-countries-'.$index" :value="$group['countries']" :error="$errors->first('groups.'.$index.'.countries') ?: null" wire:model="groups.{{ $index }}.countries" />
                    <x-admin.ui.icon-button icon="x" label="Remove group" variant="ghost" size="sm" wire:click="removeGroup({{ $index }})" />
                @else
                    <span class="rg-admin-traffic__note">Every other country</span>
                @endif
            </div>
        @endforeach
        <div class="rg-admin-traffic__row">
            <x-admin.ui.button size="sm" icon="plus" wire:click="addGroup">Add group</x-admin.ui.button>
            <x-admin.ui.button variant="primary" wire:click="saveGroups">Save groups</x-admin.ui.button>
        </div>
    </x-admin.ui.card>
</div>

<x-admin.ui.card title="What a step is worth" :description="$run === null ? 'Not worked out yet: the chances need visits two weeks old or more. Until then every step is worth $0, or what you set by hand on a game’s Value tab.' : 'Worked out '.$run['at']?->diffForHumans().' from '.number_format($run['visits']).' matured visits and '.$run['accounts'].' accounts.'">
    @if ($chances !== [])
        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid rg-admin-traffic__grid rg-admin-traffic__grid--4" role="table" aria-label="Chances">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    @foreach (['Step', 'Reached it', 'Became accounts', 'Chance'] as $heading)
                        <div class="rg-admin-table__cell" role="columnheader">{{ $heading }}</div>
                    @endforeach
                </div>
                @foreach ($chances as $chance)
                    <div class="rg-admin-table__row" role="row">
                        <div class="rg-admin-table__cell" role="cell">{{ str_replace('_', ' ', $chance['step']) }}</div>
                        <div class="rg-admin-table__cell" role="cell">{{ number_format($chance['visitors']) }}</div>
                        <div class="rg-admin-table__cell" role="cell">{{ number_format($chance['accounts']) }}</div>
                        <div class="rg-admin-table__cell" role="cell">{{ number_format($chance['chance'] * 100, 2) }}%</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-admin.ui.card>
