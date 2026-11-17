@php
    /*
     * A game's Value tab (GameValue): each step of a play, from opening the
     * page to the account, with how many new visitors reach it, their chance
     * of an account, what it is worth, and the value set by hand.
     */
    $money = fn ($amount): string => \App\Support\Traffic\Money\TrafficMoney::format($amount, 4);
@endphp

<div class="rg-admin rg-admin-screen rg-admin-games">
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-screen__body">
        @if ($run === null)
            <x-admin.ui.inline-notice tone="info">Not worked out yet: the chances need paid and unpaid visits two weeks old or more. Until then a step is worth what you set here, or $0.</x-admin.ui.inline-notice>
        @endif
        <p class="rg-admin-games__note">An account is worth {{ $money($accountWorth) }} before bonuses, in a group worth 100%. Prices are on Growth → Traffic sources → Value.</p>

        <div class="rg-admin-table rg-admin-table__scroll">
            <div class="rg-admin-table__grid rg-admin-traffic__grid rg-admin-traffic__grid--6" role="table" aria-label="What each step is worth">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    @foreach (['Step', 'Reached', 'Then an account', 'Worth', 'By hand', ''] as $heading)
                        <div class="rg-admin-table__cell" role="columnheader">{{ $heading }}</div>
                    @endforeach
                </div>
                @foreach ($steps as $row)
                    <div class="rg-admin-table__row" role="row" wire:key="value-{{ $row['step'] }}" data-testid="game-value-{{ $row['step'] }}">
                        <div class="rg-admin-table__cell" role="cell">
                            {{ $row['label'] }}
                            @foreach ($row['tags'] as $tag)
                                <x-admin.ui.badge tone="outline">{{ $tag }}</x-admin.ui.badge>
                            @endforeach
                        </div>
                        <div class="rg-admin-table__cell" role="cell">{{ $row['reached'] === null ? '—' : number_format($row['reached'] * 100, 1).'%' }}</div>
                        <div class="rg-admin-table__cell" role="cell">{{ number_format($row['chance'] * 100, 2) }}%</div>
                        <div class="rg-admin-table__cell" role="cell">{{ $money($row['worth']) }} <span class="rg-admin-games__note">{{ $row['basis'] }}</span></div>
                        <div class="rg-admin-table__cell" role="cell">
                            @unless (in_array($row['step'], ['account', 'login'], true))
                                <div class="rg-admin-traffic__row">
                                    <input class="rg-admin-traffic__select" type="text" inputmode="decimal" aria-label="Value of {{ $row['label'] }}" wire:model="manual.{{ $row['step'] }}.value" placeholder="$" size="6" />
                                    <select class="rg-admin-traffic__select" wire:model="manual.{{ $row['step'] }}.mode" aria-label="When it counts">
                                        <option value="thin">While data is thin</option>
                                        <option value="always">Always</option>
                                    </select>
                                </div>
                            @endunless
                        </div>
                        <div class="rg-admin-table__cell" role="cell">
                            @unless (in_array($row['step'], ['account', 'login'], true))
                                <x-admin.ui.button size="sm" wire:click="setValue('{{ $row['step'] }}')">Set</x-admin.ui.button>
                                @if ($row['manual'] !== null)
                                    <x-admin.ui.button size="sm" wire:click="clearValue('{{ $row['step'] }}')">Remove</x-admin.ui.button>
                                @endif
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
