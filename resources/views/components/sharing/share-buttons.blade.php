<div
    wire:ignore
    {{ $attributes->merge(['class' => 'space-y-4']) }}
    data-testid="share-buttons"
>
    {{-- URL input with copy button inside --}}
    @if(isset($enabledProviders['copy_link']))
        <x-share.copy-link-button
            :url="$metadata->url"
            :label="__('sharing.copy_link')"
            :copiedLabel="__('sharing.copied')"
        />
    @endif

    {{-- Social platform buttons --}}
    @php
        $visibleProviders = collect($socialProviderKeys)->filter(
            fn ($provider) => isset($providerUrls[$provider]) && $providerUrls[$provider] !== null
        );

        $hasNative = isset($enabledProviders['native']);
    @endphp

    @if($hasNative || $visibleProviders->isNotEmpty())
        <div>
            <p class="mb-2 text-xs font-medium text-rg-muted">{{ __('sharing.share_via') }}</p>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {{-- Native Web Share --}}
                @if($hasNative)
                    <x-share.native-share-button
                        :title="$metadata->title"
                        :text="$metadata->description"
                        :url="$metadata->url"
                        :label="__('sharing.native')"
                    />
                @endif

                {{-- Social links --}}
                @foreach ($visibleProviders as $provider)
                    <x-share.provider-link
                        :provider="$provider"
                        :url="$providerUrls[$provider]"
                        :label="__('sharing.' . $provider)"
                    />
                @endforeach
            </div>
        </div>
    @endif
</div>
