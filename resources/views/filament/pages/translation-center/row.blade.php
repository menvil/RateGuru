@php
    /*
     * One unit (DOM-01): what it is, its English reference, and the target
     * field — the field level with the English text, and under it, as the
     * length is under the English text, its count, its state and note, and
     * the actions. The
     * state badge is decided in one place, the browser's state():
     * Saved is success with the live dot, Missing is warning, AI suggestion ·
     * not saved is info with its dot on an info-tinted field and cell, Edited ·
     * not saved is outline with the field's gray-400 border. The server draws
     * the state the row opens in; the browser keeps it from then on.
     *
     * The field is a single-line input or a textarea, as the content's own
     * editor has it, and is bound to the browser's draft only: typing sends
     * nothing, and nothing is stored until Save. AI translate (on a missing
     * row), Suggest alternative (on a saved one, whose saved version stays
     * stored until Save) and Regenerate (on an AI suggestion) ask for a
     * suggestion for this row alone; while one is on its way the field is
     * read-only and the row's actions wait, and the rest of the screen stays
     * usable.
     */
    $dom = $row['dom'];
    $state = $row['stored'] === '' ? 'missing' : 'saved';
    $length = mb_strlen($row['stored']);
    $describedBy = "{$dom}-note {$dom}-counter {$dom}-bulk";
    $placeholder = 'Missing · type a translation or use AI translate';
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

    <div
        class="rg-admin-translation-row__cell rg-admin-translation-row__target"
        role="cell"
        aria-busy="false"
        x-bind:aria-busy="waiting(unit) ? 'true' : 'false'"
        x-bind:class="{ 'rg-admin-translation-row__target--generated': state(unit) === 'ai' }"
    >
        <span class="rg-admin-translation-row__cell-label">{{ $target['native'] }} · {{ $target['code'] }}</span>
        <label for="{{ $dom }}-field" class="rg-admin-sr-only">{{ $fieldLabel }}</label>
        {{-- The field first, level with the English text beside it; its state and count sit under it. --}}
        @if ($row['multiline'])
            <textarea
                id="{{ $dom }}-field"
                rows="{{ $rowsTall }}"
                lang="{{ $target['code'] }}"
                placeholder="{{ $placeholder }}"
                class="rg-admin-textarea rg-admin-textarea--compact rg-admin-translation-row__field-control"
                aria-describedby="{{ $describedBy }}"
                x-model="units[unit].value"
                x-on:input="edited(unit)"
                x-bind:readonly="units[unit].saving || waiting(unit)"
                x-bind:class="{ 'rg-admin-textarea--changed': state(unit) === 'edited', 'rg-admin-textarea--info': state(unit) === 'ai', 'rg-admin-textarea--invalid': error(unit) !== null }"
                x-bind:aria-invalid="error(unit) !== null ? 'true' : false"
                x-bind:aria-describedby="error(unit) !== null ? '{{ $describedBy }} {{ $dom }}-error' : '{{ $describedBy }}'"
            >{{ $row['stored'] }}</textarea>
        @else
            <div
                class="rg-admin-input rg-admin-translation-row__field-control"
                x-bind:class="{ 'rg-admin-input--changed': state(unit) === 'edited', 'rg-admin-input--info': state(unit) === 'ai', 'rg-admin-input--invalid': error(unit) !== null }"
            >
                <input
                    id="{{ $dom }}-field"
                    type="text"
                    lang="{{ $target['code'] }}"
                    value="{{ $row['stored'] }}"
                    placeholder="{{ $placeholder }}"
                    autocomplete="off"
                    class="rg-admin-input__control"
                    aria-describedby="{{ $describedBy }}"
                    x-model="units[unit].value"
                    x-on:input="edited(unit)"
                    x-bind:readonly="units[unit].saving || waiting(unit)"
                    x-bind:aria-invalid="error(unit) !== null ? 'true' : false"
                    x-bind:aria-describedby="error(unit) !== null ? '{{ $describedBy }} {{ $dom }}-error' : '{{ $describedBy }}'"
                />
            </div>
        @endif

        <p id="{{ $dom }}-error" class="rg-admin-error rg-admin-translation-row__error" x-cloak x-show="error(unit) !== null">
            <x-admin.ui.icon name="circle-alert" :size="12" />
            <span x-text="error(unit)"></span>
        </p>

        {{-- Why background generation has no suggestion here, or one too old to show: information, never a field error. --}}
        <p id="{{ $dom }}-bulk" class="rg-admin-translation-row__bulk-issue" x-cloak x-show="units[unit].bulkIssue !== null">
            <x-admin.ui.icon name="info" :size="12" />
            <span x-text="units[unit].bulkIssue"></span>
        </p>

        {{-- Under the field, as the length is under the English text: the count, the state and its note, the actions. --}}
        <div class="rg-admin-translation-row__foot">
            <span
                id="{{ $dom }}-counter"
                @class(['rg-admin-field__counter', 'rg-admin-translation-row__counter', 'rg-admin-field__counter--over' => $length > $row['max']])
                x-bind:class="{ 'rg-admin-field__counter--over': length(unit) > units[unit].max }"
            ><span x-text="length(unit) + ' / ' + figure(units[unit].max)">{{ $length }} / {{ number_format($row['max']) }}</span><span class="rg-admin-sr-only"> characters</span></span>
            <span class="rg-admin-translation-row__state">
                <x-admin.ui.badge tone="success" dot :x-cloak="$state !== 'saved'" x-show="state(unit) === 'saved'">Saved</x-admin.ui.badge>
                <x-admin.ui.badge tone="warning" :x-cloak="$state !== 'missing'" x-show="state(unit) === 'missing'">Missing</x-admin.ui.badge>
                <x-admin.ui.badge tone="info" dot x-cloak x-show="state(unit) === 'ai'">AI suggestion · not saved</x-admin.ui.badge>
                <x-admin.ui.badge tone="outline" x-cloak x-show="state(unit) === 'edited'">Edited · not saved</x-admin.ui.badge>
                <span id="{{ $dom }}-note" class="rg-admin-translation-row__note" x-show="! waiting(unit)" x-text="note(unit)">{{ $state === 'saved' ? 'Stored translation' : 'Visitors see the English text' }}</span>
                {{-- Said aloud by the page's own status regions; drawn here for whoever is looking at the row. --}}
                <span class="rg-admin-translation-row__generating" x-cloak x-show="waiting(unit)">
                    <span class="rg-admin-translation-row__spinner" aria-hidden="true"></span>
                    <span x-text="units[unit].generating ? 'Generating a suggestion from context…' : 'Generating in background…'">Generating a suggestion from context…</span>
                </span>
            </span>
            <div class="rg-admin-translation-row__actions">
                <x-admin.ui.button variant="ghost" size="sm" x-cloak x-show="isDirty(unit)" x-bind:disabled="waiting(unit) || units[unit].saving" x-on:click="discard(unit)">
                    Discard<span class="rg-admin-sr-only"> the {{ $target['label'] }} draft of {{ $row['name'] }}</span>
                </x-admin.ui.button>
                {{--
                    aria-disabled rather than disabled while a suggestion is on its way, so the button
                    keeps focus — a disabled one would drop it — and says it is paused.
                --}}
                <x-admin.ui.button
                    size="sm"
                    icon="sparkles"
                    x-show="offersAi(unit)"
                    x-bind:aria-disabled="waiting(unit) || units[unit].saving ? 'true' : 'false'"
                    x-on:click="suggest(unit)"
                >
                    <span x-text="aiLabel(unit)">{{ $state === 'saved' ? 'Suggest alternative' : 'AI translate' }}</span><span class="rg-admin-sr-only"> {{ $row['name'] }}</span>
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
