{{--
    The root element must have the exact same shape on every render regardless of
    $variant. An earlier version branched the ROOT element itself
    (@if($variant === 'inline') @include(...) @else <div>...</div> @endif), and
    that alone broke Livewire's wire:lazy nested-child tracking for the 'menu'
    variant used in comment-item.blade.php ("Invalid Livewire child tag name"),
    even though that variant's own rendered output never changed — merely the
    possibility of a differently-shaped root was enough to confuse it. Keeping
    one unconditional root and branching only the content inside it avoids that.
--}}
<div
    class="leading-none"
    x-data="{ reportOpen: {{ $variant === 'inline' ? 'true' : 'false' }} }"
    @keydown.escape.window="reportOpen = false"
>
    @unless($variant === 'inline')
        <button
            type="button"
            data-testid="report-button"
            @click="reportOpen = true"
            @class([
                'cursor-pointer transition',
                'inline-flex h-5 items-center text-xs font-semibold leading-none text-rg-muted hover:text-rg-dangerText' => $variant !== 'menu',
                'flex w-full items-center rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-dangerText hover:bg-rg-dangerSoft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-dangerText' => $variant === 'menu',
            ])
        >
            {{ __('ui.report.title') }}
        </button>
    @endunless

    @if($variant === 'inline')
        {{-- Inline variant renders just the form content; the caller owns the
             trigger button and the modal wrapper (see profile-page.blade.php). --}}
        @include('livewire.reports._report-modal-content')
    @else
        <x-ui.modal title="{{ __('ui.report.modal_title') }}" state="reportOpen" data-testid="report-modal">
            @include('livewire.reports._report-modal-content')
        </x-ui.modal>
    @endif
</div>
