{{--
    A source's editor: one draft for its link and parameters, postbacks,
    spend and blocking, saved together; its API connected or disconnected at
    once, the key never shown again.
--}}
@php
    $s = $draft['source'];
    $network = ($s['type'] ?? 'network') === 'network';
    $field = fn (string $key): ?string => $errors->first($key) ?: null;
@endphp
<x-admin.ui.drawer id="rg-admin-traffic-source" editor :title="$editor['new'] ? 'New source' : ($s['name'] ?? 'Source')" :subtitle="($editor['preset']['name'] ?? ($network ? 'Network' : 'Banner or link')).($editor['preset']['window_note'] ?? '' ? ' · '.$editor['preset']['window_note'] : '')" wire:key="source-editor-{{ $edit }}" x-on:dismiss="$wire.closeEditor()">
    @if (($editor['preset']['fill'] ?? '') !== '')
        <x-admin.ui.inline-notice tone="info">Fill in: {{ $editor['preset']['fill'] }}</x-admin.ui.inline-notice>
    @endif

    <div class="rg-admin-traffic__section">
        <x-admin.ui.text-field label="Name" id="rg-admin-source-name" :value="$s['name'] ?? ''" :error="$field('name')" wire:model="draft.source.name" />
        <x-admin.ui.text-field label="Key (rg_src)" id="rg-admin-source-key" :value="$s['link_key'] ?? ''" hint="In every link; it can’t change once visits came through it." :error="$field('link_key')" wire:model="draft.source.link_key" />
        <x-admin.ui.segmented label="State" :options="['active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended']" :value="$s['status'] ?? 'active'" wire:model="draft.source.status" />
        <div class="rg-admin-traffic__row">
            <label class="rg-admin-field">
                <span class="rg-admin-label">Window</span>
                <select class="rg-admin-traffic__select" wire:model="draft.source.attribution_days">
                    @foreach ([1, 2, 3, 7, 30] as $days)
                        <option value="{{ $days }}">{{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="rg-admin-field">
                <span class="rg-admin-label">Network’s day</span>
                <select class="rg-admin-traffic__select" wire:model="draft.source.reporting_timezone">
                    @foreach (array_unique([$s['reporting_timezone'] ?? 'UTC', ...$editor['timezones']]) as $zone)
                        <option value="{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </select>
            </label>
            <x-admin.ui.text-field label="Target price of an account ($)" id="rg-admin-source-cpa" :value="(string) ($s['target_cpa'] ?? '')" :error="$field('target_cpa')" wire:model="draft.source.target_cpa" />
        </div>
    </div>

    @if ($network)
        <div class="rg-admin-traffic__section">
            <span class="rg-admin-label">Link parameters</span>
            @error('link_params')<p class="rg-admin-traffic__check--danger">{{ $message }}</p>@enderror
            @foreach ($s['link_params'] ?? [] as $index => $param)
                <div class="rg-admin-traffic__row" wire:key="param-{{ $index }}">
                    <select class="rg-admin-traffic__select" wire:model="draft.source.link_params.{{ $index }}.kind" aria-label="Carries">
                        @foreach (\App\Enums\TrafficLinkParam::cases() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->value }}</option>
                        @endforeach
                    </select>
                    <x-admin.ui.text-field label="Label" :id="'rg-admin-param-label-'.$index" :value="$param['label']" :error="$field('link_params.'.$index.'.label')" wire:model="draft.source.link_params.{{ $index }}.label" />
                    <x-admin.ui.text-field label="Parameter" :id="'rg-admin-param-name-'.$index" :value="$param['param']" :error="$field('link_params.'.$index.'.param')" wire:model="draft.source.link_params.{{ $index }}.param" />
                    <x-admin.ui.text-field label="Network’s token" :id="'rg-admin-param-token-'.$index" :value="$param['token']" :error="$field('link_params.'.$index.'.token')" wire:model="draft.source.link_params.{{ $index }}.token" />
                    <x-admin.ui.icon-button icon="x" label="Remove parameter" variant="ghost" size="sm" wire:click="removeParam({{ $index }})" />
                </div>
            @endforeach
            <x-admin.ui.button size="sm" icon="plus" wire:click="addParam">Add parameter</x-admin.ui.button>
        </div>

        <div class="rg-admin-traffic__section">
            <span class="rg-admin-label">Postbacks</span>
            @foreach ($draft['rules'] as $index => $rule)
                <div class="rg-admin-traffic__rule" wire:key="rule-{{ $index }}">
                    <div class="rg-admin-traffic__row">
                        <select class="rg-admin-traffic__select" wire:model="draft.rules.{{ $index }}.event" aria-label="Event">
                            @foreach ($editor['events'] as $event)
                                <option value="{{ $event }}">{{ str_replace('_', ' ', $event) }}</option>
                            @endforeach
                        </select>
                        <x-admin.ui.text-field label="Goal code" :id="'rg-admin-rule-goal-'.$index" :value="(string) ($rule['goal_code'] ?? '')" wire:model="draft.rules.{{ $index }}.goal_code" />
                        <x-admin.ui.switch label="On" :id="'rg-admin-rule-on-'.$index" :checked="(bool) $rule['enabled']" wire:model="draft.rules.{{ $index }}.enabled" />
                    </div>
                    <x-admin.ui.text-field label="Address" :id="'rg-admin-rule-url-'.$index" :value="(string) $rule['url_template']" hint="{click_id}, {value}, {value_total}, {event}, {goal_code}, {device}, {game}, {version}, {param:name}" :error="$field('rules.'.$index.'.url_template')" wire:model="draft.rules.{{ $index }}.url_template" />
                    <div class="rg-admin-traffic__row">
                        <x-admin.ui.button size="sm" wire:click="testRule({{ $index }})">Send test</x-admin.ui.button>
                        <x-admin.ui.button size="sm" icon="x" wire:click="removeRule({{ $index }})">Remove</x-admin.ui.button>
                    </div>
                </div>
            @endforeach
            <x-admin.ui.button size="sm" icon="plus" wire:click="addRule">Add postback</x-admin.ui.button>
        </div>
    @endif

    <div class="rg-admin-traffic__section">
        <span class="rg-admin-label">Spend</span>
        <select class="rg-admin-traffic__select" wire:model.live="draft.source.spend_mode" aria-label="Spend comes from">
            @foreach ($network
                ? ['click_cost' => 'The price in each click', 'rate' => 'A rate per device', 'api' => 'The network’s API', 'manual' => 'Typed in or a file', 'none' => 'Not counted']
                : ['fixed' => 'A fixed price', 'manual' => 'Typed in or a file', 'none' => 'Not counted'] as $mode => $name)
                <option value="{{ $mode }}">{{ $name }}</option>
            @endforeach
        </select>
        @error('spend_mode')<p class="rg-admin-traffic__check--danger">{{ $message }}</p>@enderror
        @if (($s['spend_mode'] ?? '') === 'rate')
            <div class="rg-admin-traffic__row">
                <x-admin.ui.text-field label="Mobile ($)" id="rg-admin-source-rate-mobile" :value="(string) ($s['rate_mobile'] ?? '')" :error="$field('rate_mobile')" wire:model="draft.source.rate_mobile" />
                <x-admin.ui.text-field label="Desktop ($)" id="rg-admin-source-rate-desktop" :value="(string) ($s['rate_desktop'] ?? '')" wire:model="draft.source.rate_desktop" />
                <select class="rg-admin-traffic__select" wire:model="draft.source.rate_unit" aria-label="Per">
                    <option value="click">a click</option>
                    <option value="cpm">a thousand</option>
                </select>
            </div>
        @endif
        @if (($s['spend_mode'] ?? '') === 'fixed')
            <div class="rg-admin-traffic__row">
                <x-admin.ui.text-field label="Price ($)" id="rg-admin-source-fixed" :value="(string) ($s['fixed_amount'] ?? '')" :error="$field('fixed_amount')" wire:model="draft.source.fixed_amount" />
                <select class="rg-admin-traffic__select" wire:model="draft.source.fixed_period" aria-label="Per">
                    @foreach (['once' => 'once', 'day' => 'a day', 'week' => 'a week', 'month' => 'a month'] as $period => $name)
                        <option value="{{ $period }}">{{ $name }}</option>
                    @endforeach
                </select>
                <x-admin.ui.text-field label="From" id="rg-admin-source-from" type="date" :value="(string) ($s['fixed_from'] ?? '')" :error="$field('fixed_from')" wire:model="draft.source.fixed_from" />
                <x-admin.ui.text-field label="To" id="rg-admin-source-to" type="date" :value="(string) ($s['fixed_to'] ?? '')" :error="$field('fixed_to')" wire:model="draft.source.fixed_to" />
            </div>
        @endif
    </div>

    @if ($network)
        <div class="rg-admin-traffic__section">
            <span class="rg-admin-label">Blocking</span>
            <x-admin.ui.segmented label="Block by" :options="['site' => 'Site', 'zone' => 'Zone']" :value="$s['block_level'] ?? 'site'" wire:model="draft.source.block_level" />
            <x-admin.ui.switch label="Block bots and losing sites by itself" id="rg-admin-source-auto" :checked="(bool) ($s['block_auto'] ?? false)" on-hint="Every night, through the network’s API." off-hint="Only you block." wire:model="draft.source.block_auto" />
            @error('block_auto')<p class="rg-admin-traffic__check--danger">{{ $message }}</p>@enderror
        </div>

        @if (! $editor['new'] && $editor['connector'] !== null)
            <div class="rg-admin-traffic__section">
                <span class="rg-admin-label">{{ $editor['connector']['name'] ?? 'API' }} @unless ($editor['connector']['verified'])<x-admin.ui.badge tone="warning">Unverified</x-admin.ui.badge>@endunless</span>
                <p class="rg-admin-traffic__note">{{ $editor['connector']['promise'] ?? '' }}</p>
                @if ($editor['connection'] !== null && $editor['connection']['status']->value !== 'disconnected')
                    <p class="rg-admin-traffic__note">{{ ucfirst(str_replace('_', ' ', $editor['connection']['status']->value)) }}{{ $editor['connection']['account_label'] ? ' as '.$editor['connection']['account_label'] : '' }}{{ $editor['connection']['last_synced_at'] ? ' · synced '.$editor['connection']['last_synced_at']->diffForHumans() : '' }}{{ $editor['connection']['last_error'] ? ' · '.\App\Enums\TrafficConnectorFailure::tryFrom($editor['connection']['last_error'])?->message() : '' }}</p>
                    <div class="rg-admin-traffic__row">
                        <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="syncNow">Sync now</x-admin.ui.button>
                        <x-admin.ui.button size="sm" wire:click="disconnectApi">Disconnect</x-admin.ui.button>
                    </div>
                @endif
                <div class="rg-admin-traffic__row">
                    <x-admin.ui.text-field :label="$editor['connector']['credential_label'] ?? 'Key'" id="rg-admin-source-secret" type="password" :value="''" :hint="$editor['connector']['credential_help'] ?? null" wire:model="secret" autocomplete="off" />
                    <x-admin.ui.button size="sm" wire:click="connectApi">{{ $editor['connection'] !== null && $editor['connection']['status']->value !== 'disconnected' ? 'Reconnect' : 'Connect' }}</x-admin.ui.button>
                </div>
            </div>
        @endif
    @endif

    <x-slot:footer>
        <x-admin.ui.button wire:click="closeEditor">Cancel</x-admin.ui.button>
        <x-admin.ui.button variant="primary" wire:click="saveSource" data-testid="traffic-source-save">Save</x-admin.ui.button>
    </x-slot:footer>
</x-admin.ui.drawer>
