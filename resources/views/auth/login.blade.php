<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <x-auth.panel mode="login" />
    {{-- A sign-in or sign-up form shown: the traffic tracker counts the visit as having seen one. --}}
    <div hidden x-data x-init="window.rgTrafficSignal?.('signup_form')"></div>
</x-guest-layout>
