{{--
    Where visitors meet these texts, drawn from the draft as it is typed: the
    site header with the name, the tagline and the upload button, the feed's
    title and count in the plural, and the singular in a post's links. A
    picture: it is hidden from assistive technology, which reads the fields.
--}}
<x-admin.ui.card flush>
    <div class="rg-admin-card__body">
        <div class="rg-admin-section-label">
            <h2 class="rg-admin-section-label__text">Where visitors see it</h2>
            <span class="rg-admin-section-label__rule" aria-hidden="true"></span>
            <span class="rg-admin-section-label__trailing">Live preview</span>
        </div>
    </div>
    <div class="rg-admin-card__body">
        <div class="rg-admin-settings__preview" aria-hidden="true">
            <div class="rg-admin-settings__preview-header">
                <span class="rg-admin-settings__preview-name" x-text="$wire.data.site_name"></span>
                <span class="rg-admin-settings__preview-tagline" x-text="$wire.data.site_tagline"></span>
                <span class="rg-admin-settings__preview-cta"><x-admin.ui.icon name="plus" :size="14" /><span x-text="$wire.data.upload_cta_label"></span></span>
            </div>
            <div class="rg-admin-settings__preview-feed">
                <div class="rg-admin-settings__preview-title">
                    <span x-text="$wire.data.feed_title"></span>
                    <span class="rg-admin-hint" x-text="`{{ number_format($published) }} ${String($wire.data.object_plural_name ?? '').toLowerCase()} · sorted by ${$wire.data.default_sort}`"></span>
                </div>
                <div class="rg-admin-settings__preview-grid">
                    @foreach (range(1, 3) as $tile)
                        <span class="rg-admin-settings__preview-tile"><x-admin.ui.icon name="image" :size="20" /></span>
                    @endforeach
                </div>
                <p class="rg-admin-hint" x-text="`1 ${String($wire.data.object_singular_name ?? '').toLowerCase()} saved · Report this ${String($wire.data.object_singular_name ?? '').toLowerCase()}`"></p>
            </div>
        </div>
    </div>
</x-admin.ui.card>
