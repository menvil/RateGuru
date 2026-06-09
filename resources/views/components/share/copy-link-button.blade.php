@props([
    'url',
    'label' => 'Copy link',
    'copiedLabel' => 'Copied',
])

<div
    x-data="{
        copied: false,
        failed: false,
        manualCopy: false,
        async copyToClipboard() {
            this.copied = false;
            this.failed = false;
            this.manualCopy = false;

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(this.$refs.copyInput.value);
                } else {
                    this.$refs.copyInput.select();
                    const success = document.execCommand('copy');

                    if (! success) {
                        this.showManualCopy();
                        return;
                    }
                }

                this.copied = true;
                setTimeout(() => this.copied = false, 1600);
            } catch (error) {
                this.showManualCopy();
            }
        },
        showManualCopy() {
            this.failed = true;
            this.manualCopy = true;

            this.$nextTick(() => {
                this.$refs.copyInput.focus();
                this.$refs.copyInput.select();
            });
        }
    }"
    data-testid="copy-link-button"
    class="space-y-2"
>
    <input
        x-ref="copyInput"
        type="text"
        readonly
        value="{{ $url }}"
        class="rounded-rgControl border border-rg-border bg-rg-card2 px-3 py-2 font-mono text-xs text-rg-text outline-none"
        :class="{ 'sr-only': ! manualCopy, 'w-full': manualCopy }"
        data-testid="copy-link-fallback-input"
        aria-label="Link to copy manually"
    >

    <button
        type="button"
        @click="copyToClipboard"
        data-testid="share-copy-link"
        class="inline-flex h-[34px] items-center justify-center rounded-rgControl border border-rg-border bg-rg-card2 px-3 text-xs font-semibold text-rg-text2 transition-colors hover:border-rg-border2 hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
    >
        <span x-show="! copied">{{ $label }}</span>
        <span x-show="copied" x-cloak>{{ $copiedLabel }}</span>
    </button>

    <p
        x-show="failed"
        x-cloak
        data-testid="copy-link-error"
        class="text-xs text-rg-danger"
    >
        Could not copy automatically. Copy the selected link manually.
    </p>
</div>
