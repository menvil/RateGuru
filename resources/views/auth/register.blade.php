<x-guest-layout>
    <x-auth.panel mode="register" />
    {{-- A sign-in or sign-up form shown: the traffic tracker counts the visit as having seen one. --}}
    <div hidden x-data x-init="window.rgTrafficSignal?.('signup_form')"></div>
</x-guest-layout>
