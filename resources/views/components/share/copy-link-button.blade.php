@props([
    'url',
    'label' => __('sharing.copy_link'),
    'copiedLabel' => __('sharing.copied'),
])

<div
    x-data="{
        copied: false,
        failed: false,
        async copyToClipboard() {
            this.copied = false;
            this.failed = false;

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(this.$refs.copyInput.value);
                } else {
                    this.$refs.copyInput.select();
                    const success = document.execCommand('copy');
                    if (! success) { this.failed = true; return; }
                }

                this.copied = true;
                setTimeout(() => this.copied = false, 1600);
            } catch (error) {
                this.failed = true;
            }
        }
    }"
    data-testid="copy-link-button"
    class="space-y-1.5"
>
    <div class="relative">
        <input
            x-ref="copyInput"
            type="text"
            readonly
            value="{{ $url }}"
            class="h-10 w-full rounded-rgControl border border-rg-border bg-rg-card2 py-0 pl-3 pr-12 font-mono text-xs text-rg-text outline-none transition focus-visible:border-rg-accent focus-visible:ring-2 focus-visible:ring-rg-accent/25"
            data-testid="copy-link-fallback-input"
            aria-label="{{ $label }}"
            @click="$el.select()"
        >

        <button
            type="button"
            @click="copyToClipboard"
            data-testid="share-copy-link"
            :aria-label="copied ? '{{ $copiedLabel }}' : '{{ $label }}'"
            :class="copied ? 'text-rg-accent2 cursor-default pointer-events-none' : 'cursor-pointer text-rg-muted hover:bg-rg-cardHover hover:text-rg-text'"
            class="absolute right-1 top-1 grid size-8 place-items-center rounded-rgSm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
        >
            <span x-show="!copied"><x-ui.icon name="copy" class="size-4" /></span>
            <span x-show="copied" x-cloak role="status" :aria-label="'{{ $copiedLabel }}'"><x-ui.icon name="check" class="size-4" /></span>
        </button>
    </div>

    <p
        x-show="failed"
        x-cloak
        data-testid="copy-link-error"
        class="text-xs text-rg-danger"
    >
        {{ __('sharing.copy_failed') }}
    </p>
</div>
