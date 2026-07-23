<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 2.5rem; display: flex; justify-content: flex-end;">
            <x-filament::button type="submit">
                {{ __('admin.project_settings.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
