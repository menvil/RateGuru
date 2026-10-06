@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FRM-01']])
    <div class="rg-admin-kit__row">
        <div class="rg-admin-kit__item" style="width: 320px">
            <x-admin.ui.search-field placeholder="Search title, author or post ID" name="kit-posts-search" style="width: 100%" />
            <span class="rg-admin-kit__caption">toolbar · normal</span>
        </div>
        <div class="rg-admin-kit__item" style="width: 260px">
            <x-admin.ui.search-field placeholder="Search posts, users" shortcut="⌘K" name="kit-global-search" style="width: 100%" />
            <span class="rg-admin-kit__caption">global · shortcut</span>
        </div>
        <div class="rg-admin-kit__item" style="width: 260px">
            <x-admin.ui.search-field placeholder="Search languages" name="kit-disabled-search" disabled style="width: 100%" />
            <span class="rg-admin-kit__caption">disabled</span>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FRM-02']])
    <div class="rg-admin-kit__grid rg-admin-kit__grid--wide" style="gap: 20px">
        <x-admin.ui.text-field label="Name" name="kit-name" value="Konstantin Fedorov" hint="Shown on the public profile." />
        <x-admin.ui.text-field label="Username" name="kit-username" value="biscuit_mum" error="biscuit_mum is already taken. Choose another username." :required="true" />
        <x-admin.ui.text-field label="Website" name="kit-website" placeholder="https://" :required="false" hint="Linked from the public profile." />
        <x-admin.ui.text-field label="Email" name="kit-email" type="email" value="k.fedorov@example.com" disabled hint="Changed by the user from their account settings." />
    </div>
@endcomponent

@php
    $longDescription = 'What is this pet’s personality? Pick the one option that fits best, or the one that makes everyone in the comments laugh out loud.';
    $overBy = mb_strlen($longDescription) - 120;
@endphp

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FRM-03']])
    <div class="rg-admin-kit__grid rg-admin-kit__grid--wide" style="gap: 24px">
        <x-admin.ui.textarea
            label="Moderation reason"
            name="kit-reason"
            :required="true"
            :limit="500"
            placeholder="Describe why this action is necessary…"
            hint="Shared with the author and stored in the moderation log."
        />
        <x-admin.ui.textarea
            label="Group description"
            name="kit-group-description"
            :required="false"
            :limit="120"
            :value="$longDescription"
            :error="$overBy.' over the limit. Shorten the description to 120 characters.'"
        />
        <x-admin.ui.textarea
            label="Resolution note"
            name="kit-resolution"
            :required="false"
            value="Closed after the target was hidden."
            hint="Stored with the report."
            disabled
        />
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FRM-08']])
    <div class="rg-admin-kit__row" x-data="{ mode: 'missing', theme: 'system' }">
        <div class="rg-admin-kit__item">
            <x-admin.ui.segmented id="kit-segmented" label="Show" :options="['missing' => 'Missing only', 'all' => 'All']" value="missing" x-model="mode" />
            <span class="rg-admin-kit__caption" x-text="`default · mode = ${mode}`">default · mode = missing</span>
        </div>
        <div class="rg-admin-kit__item">
            <x-admin.ui.segmented label="Default theme" :options="['light' => 'Light', 'dark' => 'Dark', 'system' => 'System']" value="system" x-model="theme" compact />
            <span class="rg-admin-kit__caption" x-text="`compact · theme = ${theme}`">compact · theme = system</span>
        </div>
    </div>
@endcomponent

@php
    $kitSections = [
        ['value' => '', 'label' => 'All sections', 'trigger' => 'All'],
        ['value' => 'project_settings', 'label' => 'Project Settings', 'count' => '4 missing'],
        ['value' => 'static_pages', 'label' => 'Static Pages', 'count' => '0 missing'],
        ['value' => 'categories', 'label' => 'Categories', 'count' => '2 missing'],
        ['value' => 'tags', 'label' => 'Tags', 'count' => '11 missing'],
    ];
@endphp

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FRM-10']])
    <div class="rg-admin-kit__row" x-data="{ section: '' }">
        <div class="rg-admin-kit__item">
            <x-admin.ui.filter-dropdown id="kit-filter-section" label="Section" :options="$kitSections" x-model="section" />
            <span class="rg-admin-kit__caption" x-text="`section = ${section === '' ? 'all' : section}`">section = all</span>
        </div>
        <div class="rg-admin-kit__item">
            <span class="rg-admin-filter-chip">Category: Dogs<button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Category: Dogs" title="Remove filter"><x-admin.ui.icon name="x" :size="12" /></button></span>
            <span class="rg-admin-kit__caption">active filter chip</span>
        </div>
    </div>
@endcomponent

@php
    /*
     * Thirty-two languages, as the reference draws the combobox at scale. Made
     * up for the specimen: nothing here reads the installed languages.
     */
    $kitLanguages = collect([
        ['bg', '🇧🇬', 'Bulgarian', 'Български', false, 63], ['ru', '🇷🇺', 'Russian', 'Русский', true, 98], ['de', '🇩🇪', 'German', 'Deutsch', false, 92],
        ['fr', '🇫🇷', 'French', 'Français', true, 100], ['es', '🇪🇸', 'Spanish', 'Español', true, 93], ['it', '🇮🇹', 'Italian', 'Italiano', false, 39],
        ['pt', '🇵🇹', 'Portuguese', 'Português', false, 0], ['pl', '🇵🇱', 'Polish', 'Polski', true, 84], ['uk', '🇺🇦', 'Ukrainian', 'Українська', true, 71],
        ['cs', '🇨🇿', 'Czech', 'Čeština', false, 60], ['sk', '🇸🇰', 'Slovak', 'Slovenčina', false, 11], ['sl', '🇸🇮', 'Slovenian', 'Slovenščina', false, 0],
        ['hr', '🇭🇷', 'Croatian', 'Hrvatski', false, 84], ['sr', '🇷🇸', 'Serbian', 'Српски', false, 25], ['ro', '🇷🇴', 'Romanian', 'Română', false, 39],
        ['hu', '🇭🇺', 'Hungarian', 'Magyar', false, 0], ['el', '🇬🇷', 'Greek', 'Ελληνικά', false, 60], ['tr', '🇹🇷', 'Turkish', 'Türkçe', false, 11],
        ['nl', '🇳🇱', 'Dutch', 'Nederlands', false, 0], ['sv', '🇸🇪', 'Swedish', 'Svenska', false, 84], ['da', '🇩🇰', 'Danish', 'Dansk', false, 25],
        ['nb', '🇳🇴', 'Norwegian', 'Norsk bokmål', false, 39], ['fi', '🇫🇮', 'Finnish', 'Suomi', false, 0], ['et', '🇪🇪', 'Estonian', 'Eesti', false, 60],
        ['lv', '🇱🇻', 'Latvian', 'Latviešu', false, 11], ['lt', '🇱🇹', 'Lithuanian', 'Lietuvių', false, 0], ['ka', '🇬🇪', 'Georgian', 'ქართული', false, 84],
        ['kk', '🇰🇿', 'Kazakh', 'Қазақ тілі', false, 25], ['ja', '🇯🇵', 'Japanese', '日本語', false, 39], ['ko', '🇰🇷', 'Korean', '한국어', false, 0],
        ['zh', '🇨🇳', 'Chinese (Simplified)', '简体中文', false, 60], ['he', '🇮🇱', 'Hebrew', 'עברית', false, 0],
    ])->map(fn (array $language): array => [
        'value' => $language[0],
        'label' => "{$language[2]} — {$language[3]}",
        'leading' => $language[1],
        'meta' => $language[0].' · '.($language[4] ? 'enabled' : 'disabled').' · '.(104 - intdiv(104 * $language[5], 100)).' missing',
        'trailing' => $language[5].'%',
        'trailingTone' => $language[5] === 100 ? 'success' : null,
        'badge' => $language[4] ? ['label' => 'Enabled', 'tone' => 'success', 'dot' => true] : ['label' => 'Disabled', 'tone' => 'neutral', 'dot' => false],
        'search' => "{$language[2]} {$language[3]} {$language[0]}",
    ])->all();
@endphp

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['FRM-11']])
    <div class="rg-admin-kit__stack" x-data="{ language: 'bg' }" style="min-height: 460px">
        <div style="width: 420px; max-width: 100%">
            <x-admin.ui.combobox
                id="kit-combobox"
                label="Target language"
                :options="$kitLanguages"
                value="bg"
                :search-placeholder="'Search '.count($kitLanguages).' target languages'"
                empty="No installed language matches."
                x-model="language"
            />
        </div>
        <span class="rg-admin-kit__caption" x-text="`language = ${language}`">language = bg</span>
    </div>
@endcomponent
