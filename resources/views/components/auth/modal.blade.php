{{--
    The one authentication dialog of the application, rendered once per page
    for guests. Anything that wants a guest to sign in only dispatches

        $dispatch('open-auth-modal', { mode: 'login' | 'register' })

    and knows nothing else about it. Login and registration are two states
    of this dialog, switched by the link at the bottom — not tabs, and not a
    second dialog. /login and /register stay the no-JavaScript fallback.
--}}
@use('App\Enums\AuthModalMode')
@use('App\Support\Auth\AuthModalState')
@use('App\Support\Auth\AuthReturnUrl')
@use('App\Support\Auth\AuthSurfaceContext')

{{-- No session means the web middleware never ran (an error page for an
     unknown URL): a form posted from there could not pass CSRF, so the page
     keeps its plain links to /login and /register instead of a dialog. --}}
@if (request()->hasSession())
@php
    $state = AuthModalState::fromRequest(request());

    // The server's view of "this page"; the dialog refreshes it from the
    // address bar when it opens, because Livewire may have changed the URL.
    $returnTo = AuthReturnUrl::resolve(request()->getRequestUri());
@endphp

<div
    x-data="rgAuthModal({ open: @js($state->open), mode: @js($state->mode->value) })"
    x-on:open-auth-modal.window="show($event.detail)"
    data-auth-modal
    data-auth-modal-open="{{ $state->open ? 'true' : 'false' }}"
    data-auth-modal-mode="{{ $state->mode->value }}"
    data-testid="auth-modal-root"
>
    <x-ui.modal state="open" size="md" trap-focus close-on-escape fit-viewport data-testid="auth-modal">
        <x-slot:title>
            @foreach (AuthModalMode::cases() as $mode)
                <span
                    x-show="mode === '{{ $mode->value }}'"
                    data-testid="auth-modal-title-{{ $mode->value }}"
                    @style(['display: none' => $state->mode !== $mode])
                >{{ $mode === AuthModalMode::Login ? __('auth.login.title') : __('auth.register.title') }}</span>
            @endforeach
        </x-slot:title>

        @foreach (AuthModalMode::cases() as $mode)
            <div
                x-show="mode === '{{ $mode->value }}'"
                data-auth-panel="{{ $mode->value }}"
                data-testid="auth-modal-{{ $mode->value }}-panel"
                @style(['display: none' => $state->mode !== $mode])
            >
                <x-auth.panel
                    :mode="$mode->value"
                    :surface="AuthSurfaceContext::MODAL"
                    :return-to="$returnTo"
                    :active="$state->isActive($mode)"
                    :notice="$state->isActive($mode) ? $state->notice : null"
                />
            </div>
        @endforeach
    </x-ui.modal>
</div>
@endif
