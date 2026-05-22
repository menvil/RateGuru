@props([
    'post',
    'url' => null,
])

@php
    $shareUrl = $url ?? canonical_post_url($post);
@endphp

<section
    {{ $attributes->merge(['class' => 'rounded-rgCard border border-rg-border bg-rg-card p-4']) }}
    data-testid="post-share-panel"
>
    <div class="flex flex-col gap-3">
        <div>
            <h3 class="text-sm font-semibold text-rg-text">Share this post</h3>
            <p class="mt-1 text-xs text-rg-muted">Copy the public post URL.</p>
        </div>

        <input
            type="text"
            readonly
            value="{{ $shareUrl }}"
            class="w-full rounded-rgControl border border-rg-border bg-rg-card2 px-3 py-2 font-mono text-xs text-rg-text outline-none"
            data-testid="post-share-url"
            aria-label="Post share URL"
        >

        <div class="flex flex-wrap items-center gap-3">
            <x-share.copy-link-button :url="$shareUrl" />

            <a
                href="{{ $shareUrl }}"
                class="text-xs font-semibold text-rg-accent2 transition hover:text-rg-accent"
            >
                Open post
            </a>
        </div>
    </div>
</section>
