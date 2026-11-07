{{--
    A Persona's avatar as the Discussion step draws it, 24 across: its photo,
    or its initials, or a person when there is no Persona. $persona is
    StudioPlanFormat::author()'s, or null.
--}}
<span class="rg-admin-avatar" aria-hidden="true">
    @if ($persona !== null && ($persona['avatar'] ?? null) !== null)
        <img src="{{ $persona['avatar'] }}" alt="" class="rg-admin-avatar__image" />
    @elseif ($persona !== null && ($persona['initials'] ?? '') !== '')
        {{ $persona['initials'] }}
    @else
        <x-admin.ui.icon name="user" :size="12" />
    @endif
</span>
