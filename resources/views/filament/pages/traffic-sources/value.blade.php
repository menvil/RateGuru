{{--
    Value, as the reference draws it: the prices of the outcomes — the
    account, its bonuses and a sign-in to an account the visitor already had,
    saved as a new set, so earlier visits keep theirs — with how often each
    came and what an account brings on average; accounts by source against
    each source's target; who counts as new; the country groups, their
    countries as chips, their share of visitors and what a finished game is
    worth in each. Beside them: what a new visitor is worth, step by step; how
    the chances are worked out; the history of the prices.
--}}
@php
    $fine = fn ($amount): string => \App\Support\Traffic\Money\TrafficMoney::fine($amount);
    $brief = fn ($amount): string => \App\Support\Traffic\Money\TrafficMoney::brief($amount);
    $share = fn (?float $rate): string => $rate === null ? '—' : \App\Filament\Resources\Games\Pages\GameValue::percent($rate);
    $grouped = collect($groups)->flatMap(fn (array $group): array => array_values(array_filter(array_map('trim', explode(',', (string) $group['countries'])))))->map(fn (string $code): string => strtoupper($code))->all();
@endphp

<div class="rg-admin-games__value">
    <div class="rg-admin-games__value-main">
        <section class="rg-admin-games__value-card" data-testid="traffic-value-prices">
            <header class="rg-admin-games__value-card-head">
                <h3 class="rg-admin-games__value-card-title">What a new user is worth</h3>
                <p class="rg-admin-games__note">Set prices for outcomes only, once for the whole site. Every step of a play is then worth the chance that a visitor who reached it ends up a confirmed account, times what an account brings. The chances come from the traffic itself, per game, source, device and country group; each game’s Value tab shows them.</p>
            </header>
            <form class="rg-admin-traffic__outcomes" wire:submit="savePrices">
                <div class="rg-admin-traffic__outcome rg-admin-traffic__outcome--head"><span>Outcome</span><span>This period</span><span>Price</span></div>
                @foreach ([
                    ['account_price', 'Account confirmed', 'A new account, signed up on any page within the source’s window after the click and confirmed', 'user-plus', number_format($outcomes['accounts']).' accounts', 'new users, confirmed', '$', false],
                    ['post_bonus', 'Published a post', 'Their first post goes live within 30 days of signing up', 'newspaper', number_format($outcomes['posted']), $share($outcomes['accounts'] > 0 ? $outcomes['posted'] / $outcomes['accounts'] : null).' of accounts', '+$', true],
                    ['returned_bonus', 'Came back', 'Back on another day within 7 days of signing up', 'rotate-ccw', number_format($outcomes['returned']), $share($outcomes['accounts'] > 0 ? $outcomes['returned'] / $outcomes['accounts'] : null).' of accounts', '+$', true],
                    ['login_price', 'Existing account signed in', 'Already had an account and signed in after the click — not a new user; times the country group’s worth, like an account', 'log-in', number_format($outcomes['logins']).' '.($outcomes['logins'] === 1 ? 'sign-in' : 'sign-ins'), 'known visitors, not new', '$', false],
                ] as [$field, $name, $note, $icon, $count, $countNote, $prefix, $bonus])
                    <div class="rg-admin-traffic__outcome">
                        <span class="rg-admin-games__value-step">
                            <span class="rg-admin-games__value-sign" aria-hidden="true"><x-admin.ui.icon :name="$icon" :size="14" /></span>
                            <span class="rg-admin-games__value-step-text">
                                <span class="rg-admin-games__value-step-name">{{ $name }} @if ($bonus)<span class="rg-admin-games__value-tag">On top of it</span>@endif</span>
                                <span class="rg-admin-games__note">{{ $note }}</span>
                            </span>
                        </span>
                        <span class="rg-admin-traffic__fig"><strong>{{ $count }}</strong><small>{{ $countNote }}</small></span>
                        <span class="rg-admin-traffic__money-field">
                            <span aria-hidden="true">{{ $prefix }}</span>
                            <input type="text" inputmode="decimal" class="rg-admin-input" aria-label="Price of {{ strtolower($name) }}" wire:model="prices.{{ $field }}" />
                        </span>
                    </div>
                    @error($field)<p class="rg-admin-games__note rg-admin-games__note--danger">{{ $message }}</p>@enderror
                @endforeach
                <div class="rg-admin-traffic__outcome rg-admin-traffic__outcome--foot">
                    <span>An account brings on average <span class="rg-admin-games__note">{{ $brief($prices['account_price']) }} + {{ $share($postRate) }} post × {{ $brief($prices['post_bonus']) }} + {{ $share($returnRate) }} back × {{ $brief($prices['returned_bonus']) }}</span></span>
                    <strong>{{ $brief($accountBrings) }}</strong>
                    <x-admin.ui.button size="sm" variant="primary" type="submit">Save prices</x-admin.ui.button>
                </div>
            </form>
        </section>

        <section class="rg-admin-games__value-card" data-testid="traffic-value-sources">
            <header class="rg-admin-games__value-card-head">
                <h3 class="rg-admin-games__value-card-title">Accounts by source</h3>
                <p class="rg-admin-games__note">The same prices for every source; what differs is what their accounts go on to do. Cost per account needs no prices at all: spend divided by confirmed accounts. Target is what you are ready to pay for an account from that source; its campaigns use it unless they have their own.</p>
            </header>
            <div class="rg-admin-games__value-table" role="table" aria-label="Accounts by source">
                <div class="rg-admin-traffic__source-row rg-admin-games__value-row--head" role="row">
                    @foreach (['Source', 'Accounts', 'Posted', 'Came back', 'Account brings', 'Target / account', 'Cost / account'] as $heading)
                        <span role="columnheader">{{ $heading }}</span>
                    @endforeach
                </div>
                @foreach ([...$sourceRows, ['id' => null, 'name' => 'All paid sources', 'kind' => 'This period', ...$paidRow, 'target' => null], ['id' => null, 'name' => 'Unpaid', 'kind' => 'Shared, other sites, search, direct, RateGuru', ...$unpaidRow, 'target' => null, 'free' => true]] as $row)
                    @php
                        $f = $row['figures'];
                        $cpa = $f->costPerAccount();
                        $state = match (true) {
                            ($row['free'] ?? false) => ['free', null],
                            $cpa === null => ['—', null],
                            $row['target'] !== null => (float) $cpa > (float) $row['target'] ? ['over target', 'bad'] : ['within target', 'good'],
                            default => (float) $cpa > (float) $row['brings'] ? ['above its worth', 'bad'] : ['below its worth', 'good'],
                        };
                    @endphp
                    <div class="rg-admin-traffic__source-row" role="row" @if ($row['id'] !== null) wire:key="value-source-{{ $row['id'] }}" @endif>
                        <span role="cell" class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__perf-title">{{ $row['name'] }}</span><span class="rg-admin-games__note">{{ $row['kind'] }}</span></span>
                        <span role="cell" class="rg-admin-traffic__fig"><strong>{{ number_format($f->accounts) }}</strong></span>
                        <span role="cell" class="rg-admin-traffic__fig">{{ $share($f->accounts > 0 ? $f->posted / $f->accounts : null) }}</span>
                        <span role="cell" class="rg-admin-traffic__fig">{{ $share($f->accounts > 0 ? $f->returned / $f->accounts : null) }}</span>
                        <span role="cell" class="rg-admin-traffic__fig">{{ $brief($row['brings']) }}</span>
                        <span role="cell">
                            @if ($row['id'] !== null)
                                <form class="rg-admin-traffic__money-field" wire:submit="saveTarget({{ $row['id'] }})">
                                    <span aria-hidden="true">$</span>
                                    <input type="text" inputmode="decimal" class="rg-admin-input" placeholder="none" aria-label="Target cost of an account from {{ $row['name'] }}" wire:model="targets.{{ $row['id'] }}" x-on:blur="$el.form.requestSubmit()" />
                                </form>
                            @endif
                        </span>
                        <span role="cell" @class(['rg-admin-traffic__fig', 'rg-admin-traffic__good' => $state[1] === 'good', 'rg-admin-traffic__bad' => $state[1] === 'bad'])>
                            @if (($row['free'] ?? false))
                                <span class="rg-admin-games__note">free</span>
                            @else
                                <strong>{{ $cpa === null ? '—' : $brief($cpa) }}</strong><small>{{ $cpa === null ? '' : $state[0] }}</small>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
            <p class="rg-admin-games__note">Cost per account is green when it is within the source’s target and red when over; a source without a target is held to what an account brings. Unpaid traffic costs nothing. Accounts count when the email is confirmed, within the source’s window after the click; posts within 30 days of signing up.</p>
        </section>

        <section class="rg-admin-games__value-card" data-testid="traffic-value-who">
            <header class="rg-admin-games__value-card-head">
                <h3 class="rg-admin-games__value-card-title">Who counts <span class="rg-admin-games__value-tag">New visitors only</span></h3>
                <p class="rg-admin-games__note">A visitor RateGuru already knows as they come is worth $0: logged in, or signed in on that browser before. One who signs in to an email, Google or Facebook account we already have is no new account either: they are worth the price of an existing account signed in, set above, times their country group’s worth. Their plays show in game analytics as engagement, not as new users.</p>
            </header>
            <div class="rg-admin-traffic__who">
                <div><span class="rg-admin-games__note">Known visitors</span><strong>{{ number_format($who['known']) }}</strong><span class="rg-admin-games__note">{{ $share($who['knownShare']) }} of everyone who opened a game · worth $0 unless they signed in</span></div>
                <div><span class="rg-admin-games__note">Paid clicks on known visitors</span><strong>{{ number_format($who['paidKnown']) }}</strong><span class="rg-admin-games__note">{{ $money($who['paidKnownSpend']) }} spent on people we had already · no postbacks sent</span></div>
                <div><span class="rg-admin-games__note">From inside RateGuru</span><strong>{{ $share($who['internalKnown']) }}</strong><span class="rg-admin-games__note">of visitors from the feed and post pages are known, so that traffic is mostly engagement</span></div>
            </div>
        </section>

        <section class="rg-admin-games__value-card" data-testid="traffic-value-groups">
            <header class="rg-admin-traffic__perf-head">
                <div class="rg-admin-games__value-card-head">
                    <h3 class="rg-admin-games__value-card-title">Country groups <span class="rg-admin-games__value-tag">Every game</span></h3>
                    <p class="rg-admin-games__note">Chances are worked out per group, since one country rarely has enough accounts. The worth says what an account, or a sign-in, from a group brings against a full one — it starts at 100%, 60% and 30% — for example as ads pay differently by country.</p>
                </div>
                <x-admin.ui.button size="sm" icon="plus" wire:click="addGroup">Add a group</x-admin.ui.button>
            </header>
            <div class="rg-admin-games__value-table" role="table" aria-label="Country groups">
                <div class="rg-admin-traffic__group-row rg-admin-games__value-row--head" role="row"><span>Group and countries</span><span>Account worth</span><span>Finished game worth</span></div>
                @foreach ($groups as $index => $group)
                    @php
                        $last = $index === count($groups) - 1;
                        $info = collect($groupRows)->firstWhere('key', $group['key']);
                        $codes = array_values(array_filter(array_map(fn (string $code): string => strtoupper(trim($code)), explode(',', (string) $group['countries']))));
                    @endphp
                    <div class="rg-admin-traffic__group-row" role="row" wire:key="group-{{ $group['key'] }}">
                        <span class="rg-admin-traffic__group-main" role="cell">
                            <span class="rg-admin-traffic__group-name">
                                @if ($last)
                                    <strong>{{ $group['name'] }}</strong>
                                @else
                                    <input type="text" class="rg-admin-input" aria-label="Name of group {{ $index + 1 }}" wire:model="groups.{{ $index }}.name" />
                                @endif
                                <span class="rg-admin-games__note">{{ $share($info['share'] ?? null) }} of visitors</span>
                                @unless ($last)
                                    <x-admin.ui.icon-button icon="trash-2" label="Remove group" variant="ghost" size="sm" wire:click="removeGroup({{ $index }})" />
                                @endunless
                            </span>
                            @if ($last)
                                <span class="rg-admin-games__note">Every country not in a group above.</span>
                            @else
                                <span class="rg-admin-traffic__chips">
                                    @foreach ($codes as $code)
                                        <span class="rg-admin-traffic__chip">{{ $code }}<button type="button" aria-label="Take {{ $countries[$code] ?? $code }} out" wire:click="removeCountry({{ $index }}, '{{ $code }}')"><x-admin.ui.icon name="x" :size="10" /></button></span>
                                    @endforeach
                                    <select class="rg-admin-traffic__select rg-admin-traffic__chip-add" aria-label="Add a country to {{ $group['name'] }}" x-on:change="$wire.addCountry({{ $index }}, $event.target.value); $event.target.value = ''">
                                        <option value="">+ Country</option>
                                        @foreach ($countries as $code => $name)
                                            @continue (in_array($code, $grouped, true))
                                            <option value="{{ $code }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </span>
                            @endif
                        </span>
                        <span role="cell" class="rg-admin-traffic__money-field">
                            <input type="text" inputmode="numeric" class="rg-admin-input" aria-label="Worth of an account from {{ $group['name'] }}, percent" wire:model="groups.{{ $index }}.percent" />
                            <span aria-hidden="true">%</span>
                        </span>
                        <span role="cell" class="rg-admin-traffic__fig">
                            <span>Mobile <strong>{{ $fine($info['mobile'] ?? '0') }}</strong></span>
                            <span>Desktop <strong>{{ $fine($info['desktop'] ?? '0') }}</strong></span>
                        </span>
                    </div>
                @endforeach
            </div>
            @foreach ($errors->getMessages() as $key => $messages)
                @if (str_starts_with($key, 'groups'))
                    <p class="rg-admin-games__note rg-admin-games__note--danger">{{ implode(' ', $messages) }}</p>
                @endif
            @endforeach
            <div class="rg-admin-traffic__rule-actions">
                <x-admin.ui.button size="sm" variant="primary" wire:click="saveGroups">Save groups</x-admin.ui.button>
                <span class="rg-admin-games__note">Visits from now on are priced in the new groups; the chances are worked out again within five minutes.</span>
            </div>
        </section>
    </div>

    <aside class="rg-admin-games__value-side">
        <section class="rg-admin-games__value-card rg-admin-games__value-visitor" data-testid="traffic-value-visitor">
            <span class="rg-admin-games__eyebrow">A new visitor</span>
            <span class="rg-admin-games__value-big">{{ $fine($visitorWorth) }}</span>
            <p class="rg-admin-games__note">What a new visitor who opens a game is worth on average, every game and source. {{ $share($visitorChance) }} of them end up a confirmed account.</p>
            <div class="rg-admin-games__worth-grid" role="table" aria-label="A new visitor's worth">
                <div class="rg-admin-games__worth-row rg-admin-games__worth-row--head" role="row"><span>Reached</span><span>Mobile</span><span>Desktop</span></div>
                @foreach ($visitorRows as $row)
                    <div class="rg-admin-games__worth-row" role="row"><span>{{ $row['label'] }}</span><span>{{ $fine($row['mobile']) }}</span><span>{{ $fine($row['desktop']) }}</span></div>
                @endforeach
            </div>
            <p class="rg-admin-games__note">A visitor is worth their best step, not the sum of them: once they confirm, they are worth exactly what an account brings.</p>
        </section>

        <section class="rg-admin-games__value-card">
            <h3 class="rg-admin-games__value-card-title">How the chances are worked out</h3>
            <ul class="rg-admin-traffic__how-list">
                <li><x-admin.ui.icon name="calendar" :size="12" />From visits whose window has closed: older than 7 days for accounts, 30 for posts. Fresh visits would make the chances look lower than they are.</li>
                <li><x-admin.ui.icon name="layers" :size="12" />Per game, source, device and country group: a finisher from one network and from a channel post are worth different amounts.</li>
                <li><x-admin.ui.icon name="merge" :size="12" />A slice with fewer than 30 accounts leans on the level above it: the source, then the game, then the whole site. The more accounts it has, the more it stands on its own.</li>
                <li><x-admin.ui.icon name="refresh-cw" :size="12" />Worked out again every night{{ $run !== null ? ', last '.$run['at']?->diffForHumans().' from '.number_format($run['visits']).' visits' : '' }}. Reports value visits still inside their window at these chances until their real outcome arrives.</li>
                <li><x-admin.ui.icon name="pencil" :size="12" />A step set by hand on a game’s Value tab wins over the worked-out worth, always or only while there is too little data.</li>
            </ul>
        </section>

        <section class="rg-admin-games__value-card">
            <h3 class="rg-admin-games__value-card-title">Price changes</h3>
            @foreach ($priceChanges as $change)
                <div class="rg-admin-traffic__price-change">
                    <span>{{ $change['summary'] }}</span>
                    <span class="rg-admin-games__note">{{ $change['at']->format('j M H:i') }} · {{ $change['by'] }}</span>
                </div>
            @endforeach
            <p class="rg-admin-games__note">Postbacks already sent keep their value. A visit from before a change carries the old worth; visits from now on carry the new one.</p>
        </section>
    </aside>
</div>
