@php
    /*
     * A game's Value tab (GameValue), as the reference draws it: what each
     * step of a play is worth in the slice the filters name, grouped as
     * playing, after the result, on the site and the account, each settable
     * by hand in place; beside it what a new visitor and an account are worth,
     * each source's figures, the game's paid traffic and its postbacks.
     */
    $fine = fn ($amount): string => \App\Support\Traffic\Money\TrafficMoney::fine($amount);
    $brief = fn ($amount): string => \App\Support\Traffic\Money\TrafficMoney::brief($amount);
    $share = fn (?float $value): string => $value === null ? '—' : \App\Filament\Resources\Games\Pages\GameValue::percent($value);
@endphp

<div class="rg-admin rg-admin-screen rg-admin-games">
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-screen__body">
        <div class="rg-admin-games__value">
            <div class="rg-admin-games__value-main">
                <div class="rg-admin-games__value-head">
                    <h2 class="rg-admin-games__value-title">What each step is worth</h2>
                    <x-admin.ui.button size="sm" :href="$pricesUrl" trailing-icon="arrow-right">Outcome prices</x-admin.ui.button>
                </div>
                <p class="rg-admin-games__value-intro">Worked out, not typed in: the chance that a visitor who reached a step ends up a confirmed account, times what an account brings. Outcome prices are set once for the whole site; here you see what they make each step of {{ $title }} worth, by source, device and country group.</p>

                <div class="rg-admin-games__value-filters">
                    <select class="rg-admin-games__select rg-admin-games__value-source" wire:model.live="source" aria-label="Source" data-testid="game-value-source">
                        @foreach ($sourceOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-admin.ui.segmented label="Device" :options="$deviceOptions" :value="$device" wire:model.live="device" compact />
                    @if (count($groupOptions) <= 4)
                        <x-admin.ui.segmented label="Country group" :options="$groupOptions" :value="$group" wire:model.live="group" compact />
                    @else
                        <select class="rg-admin-games__select rg-admin-games__value-group" wire:model.live="group" aria-label="Country group">
                            @foreach ($groupOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                <p @class(['rg-admin-games__value-basis', 'rg-admin-games__value-basis--leaning' => $basis['leaning']]) data-testid="game-value-basis">
                    <x-admin.ui.icon :name="$basis['leaning'] ? 'merge' : 'layers'" :size="14" />{{ $basis['text'] }}
                </p>

                @foreach ($sections as $key => [$name, $note])
                    @continue (empty($rows[$key]))
                    <section class="rg-admin-games__value-card" data-testid="game-value-section-{{ $key }}">
                        <header class="rg-admin-games__value-card-head">
                            <h3 class="rg-admin-games__value-card-title">{{ $name }}</h3>
                            <p class="rg-admin-games__note">{{ $note }}</p>
                        </header>
                        <div class="rg-admin-games__value-table" role="table" aria-label="{{ $name }}">
                            <div class="rg-admin-games__value-row rg-admin-games__value-row--head" role="row">
                                <span role="columnheader">Step</span>
                                <span role="columnheader">Reached</span>
                                <span role="columnheader">Then account</span>
                                <span role="columnheader">Worth</span>
                                <span role="columnheader"><span class="rg-admin-sr-only">Set by hand</span></span>
                            </div>
                            @foreach ($rows[$key] as $row)
                                <div @class(['rg-admin-games__value-row', 'rg-admin-games__value-row--editing' => $editing === $row['step']]) role="row" wire:key="value-{{ $row['step'] }}" data-testid="game-value-{{ $row['step'] }}">
                                    <span class="rg-admin-games__value-step" role="cell">
                                        <span class="rg-admin-games__value-sign" aria-hidden="true"><x-admin.ui.icon :name="$row['icon']" :size="14" /></span>
                                        <span class="rg-admin-games__value-step-text">
                                            <span class="rg-admin-games__value-step-name">{{ $row['name'] }}</span>
                                            <span class="rg-admin-games__note">{{ $row['note'] }}</span>
                                            @if ($row['manual'] || $row['halfway'] || $row['goal'] || $row['postbacks'] !== [])
                                                <span class="rg-admin-games__value-tags">
                                                    @if ($row['manual'])
                                                        <span class="rg-admin-games__value-tag rg-admin-games__value-tag--hand"><x-admin.ui.icon name="pencil" :size="10" />{{ $row['manual'] }}</span>
                                                    @endif
                                                    @if ($row['halfway'])
                                                        <span class="rg-admin-games__value-tag" title="The Halfway event of postbacks: the same event in every game, whatever its length"><x-admin.ui.icon name="gauge" :size="10" />Halfway</span>
                                                    @endif
                                                    @if ($row['goal'])
                                                        <span class="rg-admin-games__value-tag rg-admin-games__value-tag--goal" title="The goal of these flows"><x-admin.ui.icon name="target" :size="10" />Goal of {{ $row['goal'] }}</span>
                                                    @endif
                                                    @foreach ($row['postbacks'] as $postback)
                                                        <span class="rg-admin-games__value-tag" title="Sent to the source with this worth for the visitor’s slice"><x-admin.ui.icon name="arrow-up-right" :size="10" />{{ $postback }}</span>
                                                    @endforeach
                                                </span>
                                            @endif
                                        </span>
                                    </span>
                                    <span class="rg-admin-games__value-number" role="cell">{{ $share($row['reached']) }}</span>
                                    <span class="rg-admin-games__value-number" role="cell">{{ $share($row['chance']) }}</span>
                                    <span class="rg-admin-games__value-worth" role="cell">
                                        <span @class(['rg-admin-games__value-amount', 'rg-admin-games__value-amount--hand' => $row['worked'] !== null])>{{ $fine($row['worth']) }}</span>
                                        @if ($row['worked'] !== null)
                                            <span class="rg-admin-games__note">worked out {{ $fine($row['worked']) }}</span>
                                        @endif
                                    </span>
                                    <span role="cell">
                                        @if ($row['editable'])
                                            <x-admin.ui.icon-button icon="pencil" :label="'Set '.$row['name'].' by hand'" size="sm" wire:click="editStep('{{ $row['step'] }}')" data-testid="game-value-edit-{{ $row['step'] }}" />
                                        @endif
                                    </span>
                                </div>
                                @if ($editing === $row['step'])
                                    <form class="rg-admin-games__value-editor" wire:submit="saveValue" data-testid="game-value-editor">
                                        <div class="rg-admin-games__value-editor-fields">
                                            <label class="rg-admin-field rg-admin-games__value-editor-amount">
                                                <span class="rg-admin-label">Worth</span>
                                                <span class="rg-admin-games__value-money">
                                                    <span aria-hidden="true">$</span>
                                                    <input type="text" inputmode="decimal" class="rg-admin-input" aria-label="{{ $row['name'] }} in dollars" wire:model="editValue" autocomplete="off" />
                                                </span>
                                            </label>
                                            <div class="rg-admin-field">
                                                <span class="rg-admin-label">When</span>
                                                <x-admin.ui.segmented label="When" :options="['always' => 'Always', 'thin' => 'Only while data is thin']" :value="$editMode" wire:model="editMode" compact />
                                            </div>
                                        </div>
                                        <p class="rg-admin-games__note">One worth for every source, device and country of {{ $title }}. It changes postbacks and the expected value of visits still inside their window, from now on. Visits whose outcome is known keep their real value.</p>
                                        <div class="rg-admin-games__value-editor-actions">
                                            <x-admin.ui.button size="sm" variant="primary" type="submit">Save</x-admin.ui.button>
                                            <x-admin.ui.button size="sm" wire:click="cancelEdit">Cancel</x-admin.ui.button>
                                            @if ($row['manual'])
                                                <x-admin.ui.button size="sm" variant="ghost" wire:click="clearValue">Back to worked out</x-admin.ui.button>
                                            @endif
                                        </div>
                                    </form>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <aside class="rg-admin-games__value-side">
                <section class="rg-admin-games__value-card rg-admin-games__value-visitor" data-testid="game-value-visitor">
                    <span class="rg-admin-games__eyebrow">A new visitor here</span>
                    <span class="rg-admin-games__value-big">{{ $fine($visitor['worth']) }}</span>
                    <p class="rg-admin-games__note">Worth of opening the game, {{ $visitor['scope'] }}. {{ $share($visitor['chance']) }} of new visitors end up a confirmed account.</p>
                    <div class="rg-admin-games__value-brings">
                        <span class="rg-admin-games__value-brings-row"><span>An account brings</span><strong>{{ $brief($visitor['account']) }}</strong></span>
                        <span class="rg-admin-games__note">{{ $visitor['formula'] }}</span>
                    </div>
                </section>

                <section class="rg-admin-games__value-card" data-testid="game-value-by-source">
                    <h3 class="rg-admin-games__value-card-title">By source</h3>
                    <div class="rg-admin-games__value-sources" role="table" aria-label="By source">
                        <div class="rg-admin-games__value-source-row rg-admin-games__value-source-row--head" role="row">
                            <span role="columnheader">{{ $deviceLabel }}</span><span role="columnheader">Accounts</span><span role="columnheader">Finished</span><span role="columnheader">Account</span>
                        </div>
                        @foreach ($bySource as $row)
                            <button type="button" @class(['rg-admin-games__value-source-row', 'rg-admin-games__value-source-row--on' => $row['current']]) role="row" wire:click="pickSource('{{ $row['key'] }}')">
                                <span role="cell">{{ $row['name'] }}</span>
                                <span role="cell">{{ number_format($row['accounts']) }}</span>
                                <span role="cell">{{ $fine($row['finished']) }}</span>
                                <span role="cell">{{ $brief($row['account']) }}</span>
                            </button>
                        @endforeach
                    </div>
                    <p class="rg-admin-games__note">Finished: what a player who finished the game is worth. Click a source to see every step for it.</p>
                </section>

                <section class="rg-admin-games__value-card" data-testid="game-value-paid">
                    <h3 class="rg-admin-games__value-card-title">Paid traffic of this game</h3>
                    @if ($paid === null)
                        <p class="rg-admin-games__note">No paid traffic yet.</p>
                    @else
                        <dl class="rg-admin-games__value-figures">
                            <div><dt>Spent</dt><dd>{{ $brief($paid->spend) }}</dd></div>
                            <div><dt>Value so far</dt><dd>{{ $brief($paid->valueReal) }}</dd></div>
                            <div><dt>Still expected</dt><dd>{{ $brief($paid->valueExpected) }}</dd></div>
                            <div><dt>Cost per account</dt><dd>{{ $paid->costPerAccount() === null ? '—' : $brief($paid->costPerAccount()) }}</dd></div>
                            <div><dt>ROI</dt><dd @class(['rg-admin-games__value-good' => ($paid->roi() ?? 0) > 0, 'rg-admin-games__value-bad' => ($paid->roi() ?? 0) < 0])>{{ $paid->roi() === null ? '—' : (($paid->roi() > 0 ? '+' : '').number_format($paid->roi(), 0).'%') }}</dd></div>
                        </dl>
                    @endif
                    <p class="rg-admin-games__note">Last 30 days. Still expected: visits from the last 7 days, valued at the worth of the steps they reached until their real outcome arrives.</p>
                    <x-admin.ui.button size="sm" :href="$reportUrl" trailing-icon="arrow-right">Open the report</x-admin.ui.button>
                </section>

                <section class="rg-admin-games__value-card" data-testid="game-value-postbacks">
                    <h3 class="rg-admin-games__value-card-title">Postbacks that send these</h3>
                    @forelse ($postbacks as $postback)
                        <a class="rg-admin-games__value-postback" href="{{ $postback['url'] }}">
                            <x-admin.ui.icon name="arrow-up-right" :size="14" />
                            <span class="rg-admin-games__value-postback-text">
                                <span class="rg-admin-games__value-postback-name">{{ $postback['source'] }} · {{ $postback['label'] }}</span>
                                <span class="rg-admin-games__note">{{ $postback['code'] !== '' ? 'Goal '.$postback['code'].' · ' : '' }}by device and country group{{ $postback['failing'] ? ' · failing' : '' }}</span>
                            </span>
                            <span class="rg-admin-games__value-postback-range">{{ $postback['range'] }}</span>
                        </a>
                    @empty
                        <p class="rg-admin-games__note">No postbacks are on. Add them under <a href="{{ $sourcesUrl }}">Growth → Traffic sources → Sources</a>.</p>
                    @endforelse
                    @if ($postbacks !== [])
                        <p class="rg-admin-games__note">Each postback sends {value}: the worth of its step for that visitor’s source, device and country group. Sent ones keep the value they had.</p>
                    @endif
                </section>
            </aside>
        </div>
    </div>
</div>
