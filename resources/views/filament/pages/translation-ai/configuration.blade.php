{{--
    Configuration and guardrails, folded away until asked for: what
    translation runs with now, each value read where it is set. A value the
    application cannot read for itself says Not available, and what is not
    built yet says Not configured — never an assumed figure.
--}}
<section class="rg-admin-card rg-admin-translation-ai__configuration" x-data="{ open: false }">
    <h2 class="rg-admin-translation-ai__configuration-title">
        <button
            type="button"
            class="rg-admin-translation-ai__configuration-toggle"
            aria-expanded="false"
            aria-controls="rg-admin-translation-ai-configuration"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            x-on:click="open = ! open"
        >
            <x-admin.ui.icon name="settings-2" class="rg-admin-translation-ai__configuration-icon" />
            <span class="rg-admin-translation-ai__section-text">
                <span class="rg-admin-card__title">Configuration and guardrails</span>
                <span class="rg-admin-card__description">What translation runs with now, read from configuration. Limits and budgets are not built yet.</span>
            </span>
            <x-admin.ui.icon name="chevron-down" :size="16" class="rg-admin-translation-ai__configuration-chevron" x-bind:class="{ 'rg-admin-translation-ai__configuration-chevron--open': open }" />
        </button>
    </h2>

    <div id="rg-admin-translation-ai-configuration" x-cloak x-show="open">
        <dl class="rg-admin-translation-ai__settings">
            @foreach ($configuration as $setting)
                <div class="rg-admin-translation-ai__setting">
                    <dt class="rg-admin-translation-ai__setting-label">{{ $setting['label'] }}</dt>
                    <dd @class(['rg-admin-translation-ai__setting-value', 'rg-admin-translation-ai__value--muted' => $setting['muted']])>{{ $setting['value'] }}</dd>
                    @if ($setting['note'] !== null)
                        <dd class="rg-admin-translation-ai__setting-note">{{ $setting['note'] }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>
    </div>
</section>
