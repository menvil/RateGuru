<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-rg-text">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div class="rounded-rgCard border border-rg-border bg-rg-card p-5 sm:p-6">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="rounded-rgCard border border-rg-border bg-rg-card p-5 sm:p-6">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="rounded-rgCard border border-rg-border bg-rg-card p-5 sm:p-6">
                <div class="max-w-xl">
                    <livewire:settings.user-locale-settings />
                </div>
            </div>

            <div class="rounded-rgCard border border-rg-border bg-rg-card p-5 sm:p-6">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
