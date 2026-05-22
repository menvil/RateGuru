@props([
    'url',
    'label' => 'Copy link',
    'copiedLabel' => 'Copied',
])

<div
    x-data="{
        copied: false,
        failed: false,
        async copyToClipboard() {
            this.failed = false;

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(this.$refs.copyInput.value);
                } else {
                    this.$refs.copyInput.select();
                    document.execCommand('copy');
                }

                this.copied = true;
                setTimeout(() => this.copied = false, 1600);
            } catch (error) {
                this.failed = true;
            }
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
        class="sr-only"
        data-testid="copy-link-fallback-input"
    >

    <button
        type="button"
        @click="copyToClipboard"
        class="inline-flex h-[34px] items-center justify-center rounded-rgControl border border-rg-border bg-rg-card2 px-3 text-xs font-semibold text-rg-text transition-colors hover:bg-rg-card"
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
        Could not copy. Select and copy the link manually.
    </p>
</div>
