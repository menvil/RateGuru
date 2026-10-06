@php
    /*
     * One unit (DOM-01): what it is, its English reference, and the target
     * field. The state badge is decided in one place, the browser's state():
     * Saved is success with the live dot, Missing is warning, Edited · not
     * saved is outline with the field's gray-400 border. The server draws the
     * state the row opens in; the browser keeps it from then on.
     *
     * The field is a single-line input or a textarea, as the content's own
     * editor has it, and is bound to the browser's draft only: typing sends
     * nothing, and nothing is stored until Save.
     */
    $dom = $row['dom'];
    $state = $row['stored'] === '' ? 'missing' : 'saved';
    $length = mb_strlen($row['stored']);
    $describedBy = "{$dom}-note {$dom}-counter";
    $fieldLabel = "{$target['label']} translation of {$row['name']}";
    $rowsTall = $row['multiline'] ? min(8, max(3, substr_count($row['stored'], "\n") + 1 + intdiv(mb_strlen($row['stored']), 70))) : 1;
@endphp

<div
    id="{{ $dom }}"
    class="rg-admin-translation-row"
    role="row"
    data-unit="{{ $row['id'] }}"
    x-data="{ unit: @js($row['id']) }"
    x-show="shows(unit)"
    x-bind:class="{ 'rg-admin-translation-row--linked': linked === unit }"
>
    <div class="rg-admin-translation-row__cell rg-admin-translation-row__item" role="cell">
        <x-admin.ui.badge tone="outline">{{ $row['section'] }}</x-admin.ui.badge>
        <div class="rg-admin-translation-row__entity">{{ $row['entity'] }}</div>
        @if ($row['field'] !== null)
            <div class="rg-admin-translation-row__field">{{ $row['field'] }}</div>
        @endif
        <div class="rg-admin-translation-row__key">{{ $row['key'] }}</div>
        <ul class="rg-admin-translation-row__chips" aria-label="Constraints">
            <li class="rg-admin-constraint-chip">Max {{ number_format($row['max']) }}</li>
            <li class="rg-admin-constraint-chip">{{ $row['multiline'] ? 'Multiline' : 'Single line' }}</li>
            @foreach ($row['placeholders'] as $placeholder)
                <li class="rg-admin-constraint-chip rg-admin-constraint-chip--placeholder">{{ $placeholder }}</li>
            @endforeach
        </ul>
        <button type="button" class="rg-admin-translation-row__context" x-on:click="openContext(unit)">
            <x-admin.ui.icon name="info" :size="14" />
            Context<span class="rg-admin-sr-only">: {{ $row['name'] }}</span>
        </button>
    </div>

    <div class="rg-admin-translation-row__cell" role="cell">
        <span class="rg-admin-translation-row__cell-label">English · reference</span>
        <div
            @class(['rg-admin-translation-row__source', 'rg-admin-translation-row__source--long' => $row['multiline']])
            lang="en"
            @if ($row['multiline'])
                tabindex="0"
                role="region"
                aria-label="English text of {{ $row['name'] }}"
            @endif
        >{{ $row['reference'] }}</div>
        <div class="rg-admin-translation-row__meta">
            <span>{{ number_format($row['referenceLength']) }} {{ \Illuminate\Support\Str::plural('character', $row['referenceLength']) }}</span>
            <a href="{{ $row['sourceUrl'] }}" class="rg-admin-link rg-admin-link--quiet rg-admin-translation-row__source-link">
                Edit source<span class="rg-admin-sr-only">: {{ $row['name'] }}</span>
                <x-admin.ui.icon name="arrow-up-right" :size="12" />
            </a>
        </div>
    </div>

    <div class="rg-admin-translation-row__cell rg-admin-translation-row__target" role="cell">
        <span class="rg-admin-translation-row__cell-label">{{ $target['native'] }} · {{ $target['code'] }}</span>
        <div class="rg-admin-translation-row__state">
            <x-admin.ui.badge tone="success" dot :x-cloak="$state !== 'saved'" x-show="state(unit) === 'saved'">Saved</x-admin.ui.badge>
            <x-admin.ui.badge tone="warning" :x-cloak="$state !== 'missing'" x-show="state(unit) === 'missing'">Missing</x-admin.ui.badge>
            <x-admin.ui.badge tone="outline" x-cloak x-show="state(unit) === 'edited'">Edited · not saved</x-admin.ui.badge>
            <span id="{{ $dom }}-note" class="rg-admin-translation-row__note" x-text="note(unit)">{{ $state === 'saved' ? 'Stored translation' : 'Visitors see the English text' }}</span>
        </div>

        <label for="{{ $dom }}-field" class="rg-admin-sr-only">{{ $fieldLabel }}</label>
        @if ($row['multiline'])
            <textarea
                id="{{ $dom }}-field"
                rows="{{ $rowsTall }}"
                lang="{{ $target['code'] }}"
                placeholder="Missing · type a translation"
                class="rg-admin-textarea rg-admin-textarea--compact rg-admin-translation-row__field-control"
                aria-describedby="{{ $describedBy }}"
                x-model="units[unit].value"
                x-on:input="units[unit].error = null"
                x-bind:readonly="units[unit].saving"
                x-bind:class="{ 'rg-admin-textarea--changed': isDirty(unit), 'rg-admin-textarea--invalid': error(unit) !== null }"
                x-bind:aria-invalid="error(unit) !== null ? 'true' : false"
                x-bind:aria-describedby="error(unit) !== null ? '{{ $describedBy }} {{ $dom }}-error' : '{{ $describedBy }}'"
            >{{ $row['stored'] }}</textarea>
        @else
            <div
                class="rg-admin-input rg-admin-translation-row__field-control"
                x-bind:class="{ 'rg-admin-input--changed': isDirty(unit), 'rg-admin-input--invalid': error(unit) !== null }"
            >
                <input
                    id="{{ $dom }}-field"
                    type="text"
                    lang="{{ $target['code'] }}"
                    value="{{ $row['stored'] }}"
                    placeholder="Missing · type a translation"
                    autocomplete="off"
                    class="rg-admin-input__control"
                    aria-describedby="{{ $describedBy }}"
                    x-model="units[unit].value"
                    x-on:input="units[unit].error = null"
                    x-bind:readonly="units[unit].saving"
                    x-bind:aria-invalid="error(unit) !== null ? 'true' : false"
                    x-bind:aria-describedby="error(unit) !== null ? '{{ $describedBy }} {{ $dom }}-error' : '{{ $describedBy }}'"
                />
            </div>
        @endif

        <div class="rg-admin-translation-row__foot">
            <span
                id="{{ $dom }}-counter"
                @class(['rg-admin-field__counter', 'rg-admin-translation-row__counter', 'rg-admin-field__counter--over' => $length > $row['max']])
                x-bind:class="{ 'rg-admin-field__counter--over': length(unit) > units[unit].max }"
            ><span x-text="length(unit) + ' / ' + figure(units[unit].max)">{{ $length }} / {{ number_format($row['max']) }}</span><span class="rg-admin-sr-only"> characters</span></span>
            <p id="{{ $dom }}-error" class="rg-admin-error rg-admin-translation-row__error" x-cloak x-show="error(unit) !== null">
                <x-admin.ui.icon name="circle-alert" :size="12" />
                <span x-text="error(unit)"></span>
            </p>
            <div class="rg-admin-translation-row__actions">
                <x-admin.ui.button variant="ghost" size="sm" x-cloak x-show="isDirty(unit)" x-on:click="discard(unit)">
                    Discard<span class="rg-admin-sr-only"> the {{ $target['label'] }} draft of {{ $row['name'] }}</span>
                </x-admin.ui.button>
                <x-admin.ui.button size="sm" disabled x-bind:disabled="! canSave(unit)" x-on:click="save(unit)">
                    Save<span class="rg-admin-sr-only"> {{ $row['name'] }}</span>
                </x-admin.ui.button>
                <x-admin.ui.button variant="primary" size="sm" trailing-icon="arrow-down" disabled x-bind:disabled="! canSave(unit)" x-on:click="save(unit, true)">
                    Save &amp; next<span class="rg-admin-sr-only"> {{ $row['name'] }}</span>
                </x-admin.ui.button>
            </div>
        </div>
    </div>
</div>
