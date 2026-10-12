@props([
    'count',
    'note' => null,
    'clear',
])

{{--
    TBL-03. Under a table's toolbar while rows are selected: “3 selected”, the
    actions — each naming how many of the selected rows it applies to,
    “Approve selected (2)”, and unavailable while none — a note about the
    rows they will skip, and Clear selection on the right.

    The default slot is the actions, the primary one first. `clear` is what
    Clear selection runs, an Alpine expression: clear="$wire.clearSelection()".
    The count is a status region, so a screen reader hears it change.
--}}
<div {{ $attributes->class(['rg-admin-bulk-bar']) }} role="region" aria-label="Selected rows">
    <span class="rg-admin-bulk-bar__count" role="status">{{ number_format($count) }} selected</span>
    <span class="rg-admin-bulk-bar__divider" aria-hidden="true"></span>
    {{ $slot }}
    @if (filled($note))
        <span class="rg-admin-bulk-bar__note">{{ $note }}</span>
    @endif
    <x-admin.ui.button variant="ghost" size="sm" class="rg-admin-bulk-bar__clear" x-on:click="{{ $clear }}">Clear selection</x-admin.ui.button>
</div>
