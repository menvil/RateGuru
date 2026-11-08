{{--
    A count set in a table row: a minus, the number and a plus, 32 high, the
    buttons named after what they count. A step is sent as a step —
    `stepVotes(node, way, ±1)`, counted from the number on the server — and
    the number typed to `setVotes(node, way, count)`. $node, $way, $count,
    $name (what is counted, e.g. "up votes on C1") and $editable.
--}}
<div class="rg-admin-stepper rg-admin-studio__votes-mini">
    <button type="button" class="rg-admin-stepper__button" aria-label="Fewer {{ $name }}" title="Fewer {{ $name }}" wire:click="stepVotes({{ $node }}, '{{ $way }}', -1)" @disabled(! $editable || $count <= 0)>
        <x-admin.ui.icon name="minus" :size="12" />
    </button>
    <input
        type="text"
        inputmode="numeric"
        class="rg-admin-stepper__control"
        value="{{ $count }}"
        aria-label="{{ ucfirst($name) }}"
        autocomplete="off"
        x-on:change="$wire.setVotes({{ $node }}, @js($way), $event.target.value)"
        @disabled(! $editable)
    />
    <button type="button" class="rg-admin-stepper__button" aria-label="More {{ $name }}" title="More {{ $name }}" wire:click="stepVotes({{ $node }}, '{{ $way }}', 1)" @disabled(! $editable)>
        <x-admin.ui.icon name="plus" :size="12" />
    </button>
</div>
