<x-guest-layout>
    <x-auth.panel mode="register" />
    {{-- A sign-in or sign-up form shown: the traffic tracker counts the visit as having seen one. --}}
    <div hidden data-rg-traffic-signal="signup_form" data-rg-traffic-mode="register" data-rg-traffic-trigger="page"></div>
</x-guest-layout>
