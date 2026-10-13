<div
    wire:ignore
    {{ $attributes->merge(['class' => 'space-y-4']) }}
    data-testid="share-buttons"
    x-data="rgSharePicture({ pictureUrl: @js($metadata->pictureUrl), fileName: @js($pictureFileName), title: @js($metadata->title), text: @js($metadata->shareText.' '.$metadata->url) })"
    x-intersect.once="prepare()"
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
    @if($hasNative || $visibleProviders->isNotEmpty())
        <div>
            <p class="mb-2 text-xs font-medium text-rg-muted">{{ __('sharing.share_via') }}</p>

            {{-- Share-sheet style grid: colored provider chips with captions, sized for
                 comfortable thumb taps on mobile. --}}
            <div class="grid grid-cols-4 gap-x-1 gap-y-3 sm:grid-cols-5">
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
                        :takes-picture="in_array($provider, $pictureProviderKeys, true)"
                    />
                @endforeach
            </div>

            {{-- After a picture is copied for an app that is opened by link:
                 what to do with it, and the link to open. --}}
            <div
                x-show="offer"
                x-cloak
                role="status"
                data-testid="share-picture-offer"
                class="mt-3 flex items-center justify-between gap-3 rounded-rgControl border border-rg-border bg-rg-card2 px-3 py-2"
            >
                <p x-show="offer?.message" x-text="offer?.message" class="text-xs text-rg-text2"></p>
                <a
                    x-bind:href="offer?.href"
                    x-text="offer?.open"
                    x-on:click="dismissOffer()"
                    target="_blank"
                    rel="nofollow noopener noreferrer"
                    data-testid="share-picture-open"
                    class="ml-auto shrink-0 rounded-rgControl bg-rg-accent px-3 py-1.5 text-xs font-medium text-rg-onAccent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                ></a>
            </div>
        </div>
    @endif
</div>
