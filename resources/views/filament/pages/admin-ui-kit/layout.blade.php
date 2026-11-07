@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['LAY-01']])
    <section class="rg-admin-page-header rg-admin-kit__frame" aria-labelledby="kit-page-header-title">
        <div style="max-width: 600px">
            <h4 id="kit-page-header-title" class="rg-admin-page-header__title">Languages</h4>
            <p class="rg-admin-page-header__description">
                Installed languages ship with the application. Enabled languages are offered to visitors of this
                project. English is the reference and is always on.
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat"><dt class="rg-admin-stat__label">Installed</dt><dd class="rg-admin-stat__value">4</dd></div>
            <div class="rg-admin-stat"><dt class="rg-admin-stat__label">Enabled</dt><dd class="rg-admin-stat__value">2</dd></div>
            <div class="rg-admin-stat"><dt class="rg-admin-stat__label">Project translations</dt><dd class="rg-admin-stat__value">85%</dd></div>
            <div class="rg-admin-stat"><dt class="rg-admin-stat__label">Missing</dt><dd class="rg-admin-stat__value">48</dd></div>
        </dl>
    </section>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['LAY-02']])
    <div style="max-width: 520px">
        <x-admin.ui.card title="Profile" description="Public identity and contact address." :heading-level="4">
            <span style="color: var(--rg-admin-text-secondary)">Section content · fields, rows or a table</span>
            <x-slot:footer>Translations are edited in Translation Center.</x-slot:footer>
        </x-admin.ui.card>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['LAY-03']])
    <div class="rg-admin-kit__frame" style="max-width: 420px; padding: 16px 20px">
        <div class="rg-admin-section-label">
            <span class="rg-admin-section-label__text">Bulgarian</span>
            <span class="rg-admin-section-label__trailing">Never enabled</span>
        </div>
        <dl class="rg-admin-detail-list" style="margin-top: 6px">
            <div class="rg-admin-detail-row">
                <x-admin.ui.icon name="globe" class="rg-admin-detail-row__icon" />
                <dt class="rg-admin-detail-row__label">Locale</dt>
                <dd class="rg-admin-detail-row__value rg-admin-detail-row__value--mono">bg</dd>
            </div>
            <div class="rg-admin-detail-row">
                <x-admin.ui.icon name="languages" class="rg-admin-detail-row__icon" />
                <dt class="rg-admin-detail-row__label">Project content</dt>
                <dd class="rg-admin-detail-row__value">66 of 104</dd>
            </div>
            <div class="rg-admin-detail-row">
                <x-admin.ui.icon name="hard-drive" class="rg-admin-detail-row__icon" />
                <dt class="rg-admin-detail-row__label">Application catalog</dt>
                <dd class="rg-admin-detail-row__value"><x-admin.ui.badge tone="success" dot>Valid</x-admin.ui.badge></dd>
            </div>
        </dl>
        <div class="rg-admin-section-label" style="margin-top: 14px">
            <span class="rg-admin-section-label__text">Other languages</span>
            <span class="rg-admin-section-label__rule" aria-hidden="true"></span>
        </div>
    </div>
@endcomponent
