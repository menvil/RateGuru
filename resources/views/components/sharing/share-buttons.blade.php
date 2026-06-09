@php
    $enabledProviders = array_filter(
        config('share.providers', []),
        fn ($p) => $p['enabled'] ?? true
    );
@endphp

<div
    {{ $attributes->merge(['class' => 'flex min-w-0 flex-wrap gap-2']) }}
    data-testid="share-buttons"
>
    {{-- Copy Link --}}
    @if(isset($enabledProviders['copy_link']))
        <x-share.copy-link-button :url="$metadata->url" :label="__('sharing.copy_link')" :copiedLabel="__('sharing.copied')" />
    @endif

    {{-- Native Web Share (shown only on supported devices via Alpine) --}}
    @if(isset($enabledProviders['native']))
        <x-share.native-share-button
            :title="$metadata->title"
            :text="$metadata->description"
            :url="$metadata->url"
            :label="__('sharing.native')"
        />
    @endif

    {{-- Social platform links --}}
    @foreach (['facebook', 'x', 'telegram', 'whatsapp', 'reddit', 'email'] as $provider)
        @if(isset($enabledProviders[$provider]) && isset($providerUrls[$provider]))
            <x-share.provider-link
                :provider="$provider"
                :url="$providerUrls[$provider]"
                :label="__('sharing.' . $provider)"
            />
        @endif
    @endforeach

    {{-- Pinterest — only when image exists --}}
    @if(isset($enabledProviders['pinterest']) && isset($providerUrls['pinterest']) && $providerUrls['pinterest'] !== null)
        <x-share.provider-link
            provider="pinterest"
            :url="$providerUrls['pinterest']"
            :label="__('sharing.pinterest')"
        />
    @endif
</div>
