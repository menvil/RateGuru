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
