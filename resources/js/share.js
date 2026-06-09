// Alpine.js component data for native Web Share API support.
window.rgNativeShare = function ({ title, text, url }) {
    return {
        supported: typeof navigator.share === 'function',
        async share() {
            if (!this.supported) return;
            try {
                await navigator.share({ title, text, url });
            } catch (_) {
                // User cancelled or share failed — silently ignore.
            }
        },
    };
};
