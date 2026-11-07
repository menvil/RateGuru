<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <x-auth.panel mode="forgot-password" />
</x-guest-layout>
