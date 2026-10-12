{{--
    STS-05 under a record's English name: one chip per language the project
    offers besides English — white when translated, amber and “missing” for
    screen readers when not — with the language and its text as a tooltip,
    and the count of missing ones after them in the due colour. $missing is
    0 where nothing is due: an inactive category waits until it is active.
--}}
@if ($chips !== [])
    <div class="rg-admin-locale-chips rg-admin-taxonomy__chips">
        @foreach ($chips as $chip)
            <span
                @class(['rg-admin-locale-chip', 'rg-admin-locale-chip--missing' => $chip['value'] === null])
                title="{{ $chip['native'] }}: {{ $chip['value'] ?? 'missing' }}"
            >{{ $chip['code'] }}@if ($chip['value'] === null)<span class="rg-admin-sr-only">, missing</span>@endif</span>
        @endforeach
        @if ($missing > 0)
            <span class="rg-admin-locale-chips__summary">{{ number_format($missing) }} missing</span>
        @endif
    </div>
@endif
