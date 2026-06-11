<section data-testid="edit-profile-form">
    <header>
        <h2 class="text-lg font-semibold text-rg-text">
            {{ __('profile.profile') }}
        </h2>
        <p class="mt-1 text-sm text-rg-muted">
            {{ __('profile.profile_description') }}
        </p>
    </header>

    <form wire:submit="save" class="mt-6 space-y-5">
        <div>
            <x-input-label for="edit-display-name" :value="__('profile.display_name')" />
            <x-ui.input
                id="edit-display-name"
                name="display_name"
                wire:model="display_name"
                type="text"
                class="mt-1"
                autocomplete="off"
                maxlength="80"
            />
            <x-input-error class="mt-2" :messages="$errors->get('display_name')" />
        </div>

        <div>
            <x-input-label for="edit-bio" :value="__('profile.bio')" />
            <textarea
                id="edit-bio"
                wire:model="bio"
                rows="3"
                maxlength="500"
                class="mt-1 block w-full resize-none rounded-rgControl border border-rg-border2 bg-rg-card px-3 py-2 text-sm text-rg-text placeholder-rg-muted shadow-sm focus:border-rg-accent focus:outline-none focus:ring-1 focus:ring-rg-accent"
            ></textarea>
            <x-input-error class="mt-2" :messages="$errors->get('bio')" />
        </div>

        <div>
            <x-input-label for="edit-website" :value="__('profile.website')" />
            <x-ui.input
                id="edit-website"
                name="profile_website_url"
                wire:model="profile_website_url"
                type="url"
                class="mt-1"
                placeholder="https://"
                maxlength="255"
            />
            <x-input-error class="mt-2" :messages="$errors->get('profile_website_url')" />
        </div>

        <div>
            <x-input-label for="edit-visibility" :value="__('profile.rating_activity_visibility')" />
            <select
                id="edit-visibility"
                wire:model="rating_activity_visibility"
                class="mt-1 block w-full rounded-rgControl border border-rg-border2 bg-rg-card px-3 py-2 text-sm text-rg-text shadow-sm focus:border-rg-accent focus:outline-none focus:ring-1 focus:ring-rg-accent"
            >
                <option value="private">{{ __('profile.visibility_private') }}</option>
                <option value="public">{{ __('profile.visibility_public') }}</option>
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('rating_activity_visibility')" />
        </div>

        <div class="flex items-center gap-4">
            <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>

            @if(session('status') === 'profile-updated')
                <p class="text-sm text-rg-muted">{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
