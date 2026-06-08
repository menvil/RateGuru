<section data-testid="user-locale-settings">
    <header>
        <h2 class="text-base font-semibold text-rg-text">{{ __('ui.settings.language') }}</h2>
        <p class="mt-1 text-sm text-rg-muted">{{ __('Choose your preferred interface language.') }}</p>
    </header>

    <form wire:submit="save" class="mt-4 space-y-4">
        <div>
            <select
                wire:model="locale"
                id="locale"
                name="locale"
                class="block w-full max-w-xs rounded-rgControl border border-rg-border2 bg-rg-card px-3 py-2 text-sm text-rg-text focus:border-rg-accent focus:outline-none focus:ring-2 focus:ring-rg-accent/25"
            >
                @foreach($supported as $code => $info)
                    <option value="{{ $code }}">{{ $info['native'] }}</option>
                @endforeach
            </select>

            @error('locale')
                <p class="mt-1 text-xs text-rg-danger">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.button type="submit">
            {{ __('Save') }}
        </x-ui.button>
    </form>
</section>
