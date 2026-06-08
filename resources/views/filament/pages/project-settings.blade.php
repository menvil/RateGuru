<x-filament-panels::page>
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
        <p class="text-sm font-medium text-amber-800 dark:text-amber-200">Apply a preset</p>
        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
            Applying a preset will overwrite current project settings fields.
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach(\App\Filament\Pages\ProjectSettingsPage::presetOptions() as $key => $label)
                <button
                    type="button"
                    wire:click="applyPreset(@json($key))"
                    wire:confirm="Apply preset '{{ $label }}'? This will overwrite current settings."
                    class="rounded bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 hover:bg-amber-200 dark:bg-amber-900 dark:text-amber-100 dark:hover:bg-amber-800"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">
                Save settings
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
