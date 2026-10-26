{{--
    Marks an account the site runs as a Persona — a character whose posts
    and comments are written with AI — wherever it is named publicly: on its
    profile, its posts and its comments. Drawn as the neutral small badge
    (x-ui.badge), with what it means as its title and for screen readers.
    Nothing for anyone else, and nothing of how the Persona is set up.
--}}
@props(['user' => null])

@if ($user?->isPersona())
    <span
        {{ $attributes->class('inline-flex items-center rounded-rgPill border border-rg-border2 bg-rg-card2 px-2 py-0.5 text-[11px] font-semibold leading-none whitespace-nowrap text-rg-text2') }}
        data-testid="persona-badge"
        title="{{ __('ui.user.persona_hint') }}"
    >{{ __('ui.user.persona') }}<span class="sr-only">: {{ __('ui.user.persona_hint') }}</span></span>
@endif
