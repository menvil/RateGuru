@php
    /*
     * A paid source's segment in the drawer over Analytics → Campaigns
     * (TrafficSegmentEditor), as the reference draws it: the segment's name;
     * its conditions, every one of which a click must meet — what it reads,
     * picked; "is any of" or "is not"; the devices picked, or the values
     * typed — each with its ×, while another is left, and Add condition;
     * then what the conditions catch of the source's visitors as they are
     * typed. The footer deletes a segment that exists, cancels, and saves
     * once the segment is named and every condition holds a value it can
     * read. Escape, the scrim and Cancel close it, nothing saved.
     */
@endphp

<x-admin.ui.drawer
    id="rg-admin-traffic-segment"
    wide
    :title="$creating ? 'New segment' : 'Edit segment'"
    :subtitle="'A slice of '.$sourceName.' traffic, for example one publisher or mobile only'"
    x-on:dismiss="$wire.dispatch('close-traffic-segment')"
    data-testid="analytics-segment-drawer"
>
    <form id="rg-admin-traffic-segment-form" class="rg-admin-analytics__segment-form" wire:submit="save" novalidate>
        @if ($gone)
            <x-admin.ui.inline-notice tone="warning">
                This segment was deleted meanwhile, so it can’t be saved.
            </x-admin.ui.inline-notice>
        @elseif ($stale)
            <x-admin.ui.inline-notice tone="warning" data-testid="analytics-segment-stale">
                Someone saved this segment after you opened it. Reload to see their changes; what you typed here is replaced.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @endif

        <x-admin.ui.text-field
            label="Segment name"
            id="rg-admin-traffic-segment-name"
            placeholder="Publisher · site 1182"
            maxlength="120"
            :value="is_string($this->name) ? $this->name : ''"
            :error="$errors->first('name') ?: null"
            wire:model.live.debounce.500ms="name"
        />

        <fieldset class="rg-admin-analytics__segment-conditions">
            <legend class="rg-admin-analytics__segment-legend">Conditions <span class="rg-admin-analytics__segment-legend-note">· all must match</span></legend>

            @foreach ($draft as $index => $condition)
                @php($error = $errors->first('conditions.'.$index.'.values') ?: $wrong[$index])
                <div class="rg-admin-analytics__segment-condition" data-testid="analytics-segment-condition" wire:key="segment-condition-{{ $index }}">
                    <div class="rg-admin-analytics__segment-condition-head">
                        <div class="rg-admin-analytics__segment-parameters" role="radiogroup" aria-label="What condition {{ $index + 1 }} reads">
                            @foreach ($parameters as $parameter)
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked="{{ $condition['parameter'] === $parameter->value ? 'true' : 'false' }}"
                                    @class(['rg-admin-analytics__segment-parameter', 'rg-admin-analytics__segment-parameter--on' => $condition['parameter'] === $parameter->value])
                                    wire:click="pickParameter({{ $index }}, '{{ $parameter->value }}')"
                                >{{ $parameter->label() }}</button>
                            @endforeach
                        </div>
                        @if (count($draft) > 1)
                            <x-admin.ui.icon-button icon="x" label="Remove condition" variant="ghost" size="sm" wire:click="removeCondition({{ $index }})" />
                        @endif
                    </div>

                    <x-admin.ui.segmented
                        label="How condition {{ $index + 1 }} holds its values"
                        compact
                        class="rg-admin-analytics__segment-operator"
                        :options="$operators"
                        :value="$condition['operator']"
                        wire:model.live="conditions.{{ $index }}.operator"
                        wire:key="segment-operator-{{ $index }}"
                    />

                    @if ($condition['parameter'] === 'device')
                        <div class="rg-admin-analytics__segment-devices" role="group" aria-label="Devices of condition {{ $index + 1 }}">
                            @foreach ($devices as $device)
                                @php($on = in_array($device->value, \App\Support\Analytics\SegmentRules::values($condition['values']), true))
                                <button
                                    type="button"
                                    aria-pressed="{{ $on ? 'true' : 'false' }}"
                                    @class(['rg-admin-analytics__segment-device', 'rg-admin-analytics__segment-device--on' => $on])
                                    wire:click="toggleDevice({{ $index }}, '{{ $device->value }}')"
                                >{{ $device->label() }}</button>
                            @endforeach
                        </div>
                        @if ($error !== null)
                            <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $error }}</p>
                        @endif
                    @else
                        <x-admin.ui.text-field
                            label="Values"
                            id="rg-admin-traffic-segment-values-{{ $index }}"
                            :placeholder="\App\Enums\SegmentParameter::from($condition['parameter'])->hint()"
                            :value="$condition['values']"
                            :error="$error"
                            wire:model.live.debounce.500ms="conditions.{{ $index }}.values"
                        />
                    @endif
                </div>
            @endforeach

            <div>
                <x-admin.ui.button size="sm" icon="plus" wire:click="addCondition" :disabled="count($draft) >= $most">Add condition</x-admin.ui.button>
            </div>
        </fieldset>

        <div class="rg-admin-analytics__segment-preview" data-testid="analytics-segment-preview" aria-live="polite">
            <x-admin.ui.icon name="filter" :size="16" class="rg-admin-analytics__segment-preview-icon" />
            <span>{{ $preview }}</span>
        </div>
    </form>

    <x-slot:footer>
        @unless ($creating)
            <x-admin.ui.button icon="trash-2" wire:click="delete" :disabled="$gone" data-testid="analytics-segment-delete">Delete segment</x-admin.ui.button>
        @endunless
        <span class="rg-admin-analytics__segment-spacer"></span>
        <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
        <x-admin.ui.button
            variant="primary"
            type="submit"
            form="rg-admin-traffic-segment-form"
            :disabled="! $canSave"
            wire:loading.attr="disabled"
            wire:target="save"
            data-testid="analytics-segment-save"
        >Save segment</x-admin.ui.button>
    </x-slot:footer>
</x-admin.ui.drawer>
