<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <x-auth.panel mode="login" />
    {{-- A sign-in or sign-up form shown: the traffic tracker counts the visit as having seen one. --}}
    <div hidden data-rg-traffic-signal="signup_form" data-rg-traffic-mode="login" data-rg-traffic-trigger="page"></div>
</x-guest-layout>
