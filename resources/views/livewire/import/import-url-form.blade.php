<div>
    @if($enabled)
        <div data-testid="import-url-form" class="space-y-3">
            <div class="flex gap-2">
                <input
                    type="url"
                    wire:model="url"
                    placeholder="{{ __('import.paste_url') }}"
                    data-testid="import-url-input"
                    class="flex-1 rounded-rgSm border border-rg-border bg-rg-card px-3 py-2 text-sm text-rg-text placeholder:text-rg-muted focus:outline-none focus:ring-1 focus:ring-rg-border2"
                />
                <x-ui.button
                    type="button"
                    wire:click="import"
                    wire:loading.attr="disabled"
                    data-testid="import-url-submit"
                    size="sm"
                >
                    <span wire:loading.remove wire:target="import">{{ __('import.import') }}</span>
                    <span wire:loading wire:target="import">{{ __('import.loading') }}</span>
                </x-ui.button>
            </div>

            @error('url')
                <p class="text-xs text-rg-dangerText" data-testid="import-url-validation-error">{{ $message }}</p>
            @enderror

            @if($error)
                <div class="rounded-rgSm border border-rg-border bg-rg-card2 p-3" data-testid="import-error-message">
                    <p class="text-sm text-rg-dangerText">{{ $error }}</p>
                    @if($unsupported)
                        <p class="mt-1 text-xs text-rg-muted">{{ __('import.manual_upload_hint') }}</p>
                    @endif
                </div>
            @endif

            @if($previewTitle || $previewImageUrl || $previewDescription)
                <div class="rounded-rgSm border border-rg-border bg-rg-card2 p-3" data-testid="import-preview">
                    <p class="text-xs text-rg-muted">{{ __('import.preview') }}</p>

                    @if($previewImageUrl)
                        <img
                            src="{{ $previewImageUrl }}"
                            alt="{{ $previewTitle ?? '' }}"
                            referrerpolicy="no-referrer"
                            class="mt-2 h-32 w-full rounded-rgSm object-cover"
                            data-testid="import-preview-image"
                        />
                    @endif

                    @if($previewTitle)
                        <p class="mt-2 text-sm font-medium text-rg-text" data-testid="import-preview-title">{{ $previewTitle }}</p>
                    @endif

                    @if($previewDescription)
                        <p class="mt-1 text-xs text-rg-muted" data-testid="import-preview-description">{{ $previewDescription }}</p>
                    @endif

                    <x-ui.button
                        type="button"
                        wire:click="usePreview"
                        data-testid="import-use-preview"
                        size="sm"
                        class="mt-3"
                    >
                        {{ __('import.use_this') }}
                    </x-ui.button>
                </div>
            @endif
        </div>
    @endif
</div>
