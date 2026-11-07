@php
    /*
     * The context of one unit (OVL-02): where the text appears, its English
     * reference, its constraints and the other languages that already
     * translate it, read for this unit alone when the drawer opens. Other
     * languages are read-only here; each is edited by choosing it as the
     * target language. They are what AI translate passes along as context,
     * never a source: English is.
     *
     * Last, what AI translate sends for this unit — taken from the very
     * request the suggestion is made from, never written out separately here.
     * Content only: no provider, model, credential or raw request.
     */
    $subtitle = new \Illuminate\Support\HtmlString('<span x-text="context.subtitle"></span>');
@endphp

<template x-if="context">
    <x-admin.ui.drawer id="rg-admin-translation-context" title="Context" :subtitle="$subtitle" x-on:dismiss="closeContext()">
        <section class="rg-admin-translation-context__section" aria-labelledby="rg-admin-translation-context-usage">
            <div class="rg-admin-section-label">
                <h3 id="rg-admin-translation-context-usage" class="rg-admin-section-label__text">Where it appears</h3>
            </div>
            <p class="rg-admin-translation-context__usage" x-text="context.usage"></p>
        </section>

        <section class="rg-admin-translation-context__section" aria-labelledby="rg-admin-translation-context-item">
            <div class="rg-admin-section-label">
                <h3 id="rg-admin-translation-context-item" class="rg-admin-section-label__text">Item</h3>
            </div>
            <dl class="rg-admin-detail-list">
                <div class="rg-admin-detail-row">
                    <dt class="rg-admin-detail-row__label">Section</dt>
                    <dd class="rg-admin-detail-row__value" x-text="context.section"></dd>
                </div>
                <div class="rg-admin-detail-row">
                    <dt class="rg-admin-detail-row__label">Entity</dt>
                    <dd class="rg-admin-detail-row__value rg-admin-translation-context__value" x-text="context.entity" x-bind:title="context.entity"></dd>
                </div>
                <div class="rg-admin-detail-row">
                    <dt class="rg-admin-detail-row__label">Field</dt>
                    <dd class="rg-admin-detail-row__value" x-text="context.field"></dd>
                </div>
                <div class="rg-admin-detail-row">
                    <dt class="rg-admin-detail-row__label">Key</dt>
                    <dd class="rg-admin-detail-row__value rg-admin-detail-row__value--mono" x-text="context.key" x-bind:title="context.key"></dd>
                </div>
                <div class="rg-admin-detail-row">
                    <dt class="rg-admin-detail-row__label">Target language</dt>
                    <dd class="rg-admin-detail-row__value" x-text="context.target"></dd>
                </div>
            </dl>
        </section>

        <section class="rg-admin-translation-context__section" aria-labelledby="rg-admin-translation-context-reference">
            <div class="rg-admin-section-label">
                <h3 id="rg-admin-translation-context-reference" class="rg-admin-section-label__text">English reference</h3>
            </div>
            <div class="rg-admin-translation-row__source" lang="en" x-text="context.reference"></div>
        </section>

        <section class="rg-admin-translation-context__section" aria-labelledby="rg-admin-translation-context-constraints">
            <div class="rg-admin-section-label">
                <h3 id="rg-admin-translation-context-constraints" class="rg-admin-section-label__text">Constraints</h3>
            </div>
            <dl class="rg-admin-detail-list">
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="ruler" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Maximum length</dt>
                    <dd class="rg-admin-detail-row__value" x-text="context.max"></dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="text" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Format</dt>
                    <dd class="rg-admin-detail-row__value" x-text="context.format"></dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="braces" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Placeholders to keep</dt>
                    <dd class="rg-admin-detail-row__value rg-admin-detail-row__value--mono" x-text="context.placeholders.length ? context.placeholders.join(', ') : 'None'"></dd>
                </div>
            </dl>
        </section>

        <section class="rg-admin-translation-context__section" aria-labelledby="rg-admin-translation-context-others">
            <div class="rg-admin-section-label">
                <h3 id="rg-admin-translation-context-others" class="rg-admin-section-label__text">Other languages</h3>
                <span class="rg-admin-section-label__trailing">AI context only</span>
            </div>
            <p class="rg-admin-translation-context__explainer">Saved translations in other languages help AI understand terminology and tone. English remains the authoritative source.</p>
            <ul class="rg-admin-translation-context__others" x-show="context.others.length > 0">
                <template x-for="other in context.others" x-bind:key="other.code">
                    <li class="rg-admin-translation-context__other">
                        <span class="rg-admin-translation-context__flag" aria-hidden="true" x-text="other.flag"></span>
                        <span class="rg-admin-translation-context__other-text">
                            <span class="rg-admin-translation-context__other-language" x-text="other.label"></span>
                            <span class="rg-admin-translation-context__other-translation" x-bind:lang="other.code" x-text="other.text"></span>
                        </span>
                    </li>
                </template>
            </ul>
            <p class="rg-admin-translation-context__none" x-show="context.others.length === 0">No other language has a translation yet.</p>
        </section>

        <section class="rg-admin-translation-context__section rg-admin-translation-context__section--sunken" aria-labelledby="rg-admin-translation-context-ai">
            <div class="rg-admin-section-label">
                <h3 id="rg-admin-translation-context-ai" class="rg-admin-section-label__text">What AI translate sends</h3>
            </div>
            <template x-if="context.ai">
                <div>
                    <dl class="rg-admin-translation-context__payload">
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Target language</dt>
                            <dd x-text="context.ai.target"></dd>
                        </div>
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Source language</dt>
                            <dd><span x-text="context.ai.source"></span> · authoritative</dd>
                        </div>
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Source text</dt>
                            <dd class="rg-admin-translation-context__payload-text" lang="en" x-text="context.ai.sourceText"></dd>
                        </div>
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Content type</dt>
                            <dd class="rg-admin-translation-context__payload-mono" x-text="context.ai.contentType"></dd>
                        </div>
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Context</dt>
                            <dd class="rg-admin-translation-context__payload-text" x-text="context.ai.context ?? 'None'"></dd>
                        </div>
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Maximum length</dt>
                            <dd x-text="context.ai.max"></dd>
                        </div>
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Format</dt>
                            <dd x-text="context.ai.format"></dd>
                        </div>
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Placeholders</dt>
                            <dd class="rg-admin-translation-context__payload-mono" x-text="context.ai.placeholders.length ? context.ai.placeholders.join(', ') : 'None'"></dd>
                        </div>
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Other translations</dt>
                            <dd>
                                <span x-show="context.ai.others.length === 0">None</span>
                                <ul class="rg-admin-translation-context__payload-list" x-show="context.ai.others.length > 0" aria-label="Translations sent as context only">
                                    <template x-for="other in context.ai.others" x-bind:key="other.code">
                                        <li>
                                            <span class="rg-admin-translation-context__payload-language" x-text="other.label + ' · context only'"></span>
                                            <span x-bind:lang="other.code" x-text="other.text"></span>
                                        </li>
                                    </template>
                                </ul>
                                <span class="rg-admin-translation-context__payload-note" x-show="context.ai.omitted > 0" x-text="context.ai.omitted + ' more ' + (context.ai.omitted === 1 ? 'translation is' : 'translations are') + ' left out: the context they would add is too long to send.'"></span>
                            </dd>
                        </div>
                        <div class="rg-admin-translation-context__payload-row">
                            <dt>Glossary</dt>
                            <dd x-text="context.ai.glossary.length ? context.ai.glossary.join(', ') : 'None'"></dd>
                        </div>
                    </dl>
                    <p class="rg-admin-translation-context__payload-note">The model is asked to translate meaning, keep placeholders exactly and stay within the length limit. Its output is a suggestion until someone saves it.</p>
                </div>
            </template>
            <p class="rg-admin-translation-context__none" x-show="! context.ai">This item cannot be sent for machine translation; translate it manually.</p>
        </section>

        <x-slot:footer>
            <span class="rg-admin-translation-context__note">The English text is changed in its own editor.</span>
            <x-admin.ui.button href="#" trailing-icon="arrow-up-right" x-bind:href="context.sourceUrl">Edit source</x-admin.ui.button>
        </x-slot:footer>
    </x-admin.ui.drawer>
</template>
