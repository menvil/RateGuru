{{--
    A Persona as the Discussion step names it: its avatar or initials, its
    name and handle, and a badge when its account is restricted or deleted —
    a saved comment keeps its Persona whatever happened to the account.
    $persona is StudioPlanFormat::author()'s, or null; $missing is said in
    its place.
--}}
<span class="rg-admin-studio__persona">
    <span class="rg-admin-avatar rg-admin-avatar--md" aria-hidden="true">
        @if ($persona !== null && ($persona['avatar'] ?? null) !== null)
            <img src="{{ $persona['avatar'] }}" alt="" class="rg-admin-avatar__image" />
        @elseif ($persona !== null && ($persona['initials'] ?? '') !== '')
            {{ $persona['initials'] }}
        @else
            <x-admin.ui.icon name="user" :size="14" />
        @endif
    </span>
    <span class="rg-admin-table__stack">
        @if ($persona !== null)
            <span class="rg-admin-table__name rg-admin-table__ellipsis">{{ $persona['name'] }}</span>
            @if (($persona['username'] ?? null) !== null)
                <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ '@'.$persona['username'] }}</span>
            @endif
        @else
            <span class="rg-admin-studio__empty">{{ $missing ?? 'No persona' }}</span>
        @endif
    </span>
    @if ($persona !== null && ($persona['problem'] ?? null) === 'deleted')
        <x-admin.ui.badge tone="danger">Deleted</x-admin.ui.badge>
    @elseif ($persona !== null && ($persona['problem'] ?? null) === 'not_persona')
        <x-admin.ui.badge tone="danger">Not a persona</x-admin.ui.badge>
    @elseif ($persona !== null && ($persona['problem'] ?? null) === 'restricted' && ($persona['status'] ?? null) !== null)
        <x-admin.ui.badge :tone="$persona['status']['tone']" :dot="$persona['status']['dot']">{{ $persona['status']['label'] }}</x-admin.ui.badge>
    @endif
</span>
