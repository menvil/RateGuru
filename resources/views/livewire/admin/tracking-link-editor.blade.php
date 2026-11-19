@php
    /*
     * A tracking link in the drawer over Analytics → Tracking links
     * (TrackingLinkEditor), as the reference draws it: where it leads — a
     * game, a page of the site or a destination typed by hand — then its
     * campaign parameters: the internal name, the source and medium picked,
     * the campaign, content and term typed; then the address they make, with
     * Copy link, the destination's preview, and a set of tags another link
     * carries said under it. A link visits came through shows its address
     * locked and only its name to change. The footer says what is still
     * needed, cancels, and saves. Escape, the scrim and Cancel close it,
     * nothing saved.
     */
@endphp

<x-admin.ui.drawer
    id="rg-admin-tracking-link"
    wide
    :title="$title"
    :subtitle="$subtitle"
    x-on:dismiss="$wire.dispatch('close-tracking-link')"
    data-testid="analytics-link-drawer"
>
    <form id="rg-admin-tracking-link-form" class="rg-admin-analytics__link-form" wire:submit="save" novalidate>
        @if ($gone)
            <x-admin.ui.inline-notice tone="warning">This link no longer exists, so it can’t be saved.</x-admin.ui.inline-notice>
        @elseif ($stale)
            <x-admin.ui.inline-notice tone="warning" data-testid="analytics-link-stale">
                Someone saved this link after you opened it. Reload to see their changes; what you typed here is replaced.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @endif

        @unless ($editable)
            <x-admin.ui.inline-notice tone="info" icon="lock" data-testid="analytics-link-locked">
                This link already has visits, so its URL is locked to keep reports comparable. You can rename it, or duplicate it to change parameters.
            </x-admin.ui.inline-notice>
        @endunless

        <section class="rg-admin-analytics__link-section" aria-labelledby="rg-admin-tracking-link-destination">
            <div class="rg-admin-section-label"><h3 id="rg-admin-tracking-link-destination" class="rg-admin-section-label__text">Destination</h3></div>
            <x-admin.ui.segmented
                label="Destination type"
                class="rg-admin-analytics__link-destinations"
                :options="$destinations"
                :value="$destinationValue"
                :disabled="! $editable"
                wire:model.live="destination"
            />

            @if ($destinationValue === 'game' && $editable)
                <div wire:key="link-destination-game">
                    <x-admin.ui.combobox
                        label="Game"
                        id="rg-admin-tracking-link-game"
                        :options="$games"
                        :value="$gameValue"
                        search-placeholder="Search games"
                        empty="No game matches."
                        wire:model.live="game"
                    />
                </div>
            @elseif ($destinationValue === 'game')
                <div class="rg-admin-analytics__link-locked-value" wire:key="link-destination-game-locked" data-testid="analytics-link-locked-game">{{ $gameLabel ?? '—' }}</div>
            @elseif ($destinationValue === 'page')
                <div class="rg-admin-analytics__link-chips" role="radiogroup" aria-label="Site page" wire:key="link-destination-page">
                    @foreach ($pages as $path => $label)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $page === $path ? 'true' : 'false' }}"
                            @class(['rg-admin-analytics__link-chip', 'rg-admin-analytics__link-chip--on' => $page === $path])
                            @disabled(! $editable)
                            wire:click="pickPage(@js($path))"
                        >{{ $label }}</button>
                    @endforeach
                </div>
            @elseif ($editable)
                <div wire:key="link-destination-custom">
                    <x-admin.ui.text-field
                        label="Destination"
                        id="rg-admin-tracking-link-custom"
                        placeholder="/?sort=top or {{ \App\Filament\Pages\Analytics\TrackingLinksTab::origin() }}/…"
                        :value="is_string($custom) ? $custom : ''"
                        hint="An internal path or a full URL on this site. Existing parameters are kept; existing utm_ parameters are replaced."
                        wire:model.live.debounce.400ms="custom"
                    />
                </div>
            @else
                <div class="rg-admin-analytics__link-locked-value">{{ is_string($custom) ? $custom : '' }}</div>
            @endif

            @isset($wrong['destination'])
                <p class="rg-admin-error" data-testid="analytics-link-error-destination"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $wrong['destination'] }}</p>
            @endisset
            @if ($siteNote !== null)
                <p class="rg-admin-hint">{{ $siteNote }}</p>
            @endif
        </section>

        <section class="rg-admin-analytics__link-section" aria-labelledby="rg-admin-tracking-link-parameters">
            <div class="rg-admin-section-label"><h3 id="rg-admin-tracking-link-parameters" class="rg-admin-section-label__text">Campaign parameters</h3></div>

            <x-admin.ui.text-field
                label="Internal link name"
                id="rg-admin-tracking-link-name"
                placeholder="Reddit · versus_launch · post_01"
                maxlength="160"
                :value="is_string($name) ? $name : ''"
                :error="$wrong['name'] ?? null"
                wire:model.live.debounce.400ms="name"
            />

            <div class="rg-admin-analytics__link-group" role="group" aria-labelledby="rg-admin-tracking-link-source-label">
                <div class="rg-admin-analytics__link-label">
                    <span id="rg-admin-tracking-link-source-label" class="rg-admin-label">Source</span>
                    <span class="rg-admin-field__tag">utm_source · required</span>
                </div>
                <div class="rg-admin-analytics__link-chips" role="radiogroup" aria-label="Source">
                    @foreach ($sources as $key => $label)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $source === $key ? 'true' : 'false' }}"
                            @class(['rg-admin-analytics__link-chip', 'rg-admin-analytics__link-chip--on' => $source === $key])
                            @disabled(! $editable)
                            wire:click="pickSource(@js($key))"
                        >{{ $label }}</button>
                    @endforeach
                </div>
                @if ($source === 'other' && $editable)
                    <x-admin.ui.text-field label="Source name" id="rg-admin-tracking-link-source-custom" class="rg-admin-analytics__link-other" placeholder="tiktok" maxlength="64" :value="is_string($sourceCustom) ? $sourceCustom : ''" wire:model.live.debounce.400ms="sourceCustom" />
                @endif
                @isset($wrong['source'])
                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $wrong['source'] }}</p>
                @endisset
            </div>

            <div class="rg-admin-analytics__link-group" role="group" aria-labelledby="rg-admin-tracking-link-medium-label">
                <div class="rg-admin-analytics__link-label">
                    <span id="rg-admin-tracking-link-medium-label" class="rg-admin-label">Medium</span>
                    <span class="rg-admin-field__tag">utm_medium · set independently of the source</span>
                </div>
                <div class="rg-admin-analytics__link-chips" role="radiogroup" aria-label="Medium">
                    @foreach ($mediums as $each)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $medium === $each ? 'true' : 'false' }}"
                            @class(['rg-admin-analytics__link-chip rg-admin-analytics__link-chip--mono', 'rg-admin-analytics__link-chip--on' => $medium === $each])
                            @disabled(! $editable)
                            wire:click="pickMedium(@js($each))"
                        >{{ $each }}</button>
                    @endforeach
                </div>
                @isset($wrong['medium'])
                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $wrong['medium'] }}</p>
                @endisset
            </div>

            @foreach ([
                ['field' => 'campaign', 'label' => 'Campaign', 'tag' => 'utm_campaign · required', 'placeholder' => 'versus_launch', 'value' => $campaign],
                ['field' => 'content', 'label' => 'Content / creative', 'tag' => 'utm_content · optional', 'placeholder' => 'post_01', 'value' => $content],
                ['field' => 'term', 'label' => 'Keyword / term', 'tag' => 'utm_term · optional', 'placeholder' => 'taste quiz', 'value' => $term],
            ] as $text)
                @if ($editable)
                    <x-admin.ui.text-field
                        :label="$text['label']"
                        :tag="$text['tag']"
                        id="rg-admin-tracking-link-{{ $text['field'] }}"
                        :placeholder="$text['placeholder']"
                        maxlength="120"
                        :value="is_string($text['value']) ? $text['value'] : ''"
                        :error="$wrong[$text['field']] ?? null"
                        wire:model.live.debounce.400ms="{{ $text['field'] }}"
                    />
                @else
                    <div class="rg-admin-analytics__link-group">
                        <div class="rg-admin-analytics__link-label">
                            <span class="rg-admin-label">{{ $text['label'] }}</span>
                            <span class="rg-admin-field__tag">{{ $text['tag'] }}</span>
                        </div>
                        <div class="rg-admin-analytics__link-locked-value">{{ is_string($text['value']) && $text['value'] !== '' ? $text['value'] : '—' }}</div>
                    </div>
                @endif
            @endforeach
        </section>

        <section class="rg-admin-analytics__link-section" aria-labelledby="rg-admin-tracking-link-url">
            <div class="rg-admin-section-label"><h3 id="rg-admin-tracking-link-url" class="rg-admin-section-label__text">Generated URL</h3></div>
            <div @class(['rg-admin-analytics__link-url', 'rg-admin-analytics__link-url--empty' => $url === null]) data-testid="analytics-link-url">{{ $url ?? 'Choose a destination to build the URL' }}</div>
            <div class="rg-admin-analytics__link-buttons">
                {{-- Says Link copied only once the clipboard took the address: the write can be refused, and there is no clipboard outside a secure page. --}}
                <x-admin.ui.button
                    size="sm"
                    icon="copy"
                    :disabled="$url === null"
                    :data-copy="$url"
                    data-testid="analytics-link-copy"
                    x-on:click="async () => { try { await navigator.clipboard.writeText($el.dataset.copy); $dispatch('rg-admin-toast', { message: 'Link copied', tone: 'success' }) } catch { $dispatch('rg-admin-toast', { message: 'The browser refused the clipboard, so nothing was copied.', tone: 'error' }) } }"
                >Copy link</x-admin.ui.button>
                <x-admin.ui.button size="sm" icon="external-link" :href="$preview" target="_blank" :disabled="$preview === null">Open destination preview</x-admin.ui.button>
            </div>
            @if ($holding !== null && $editable)
                <x-admin.ui.inline-notice tone="warning" data-testid="analytics-link-duplicate">{{ $holding }}</x-admin.ui.inline-notice>
            @endif
            <p class="rg-admin-analytics__link-help">Ad platforms append their own click IDs, such as gclid or fbclid, when someone clicks an ad. Don’t add them here. Spaces and symbols are encoded automatically.</p>
        </section>
    </form>

    <x-slot:footer>
        <span @class(['rg-admin-analytics__link-foot', 'rg-admin-analytics__link-foot--wrong' => $foot['wrong']]) data-testid="analytics-link-foot">{{ $foot['text'] }}</span>
        <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
        <x-admin.ui.button
            variant="primary"
            type="submit"
            form="rg-admin-tracking-link-form"
            :disabled="! $canSave"
            wire:loading.attr="disabled"
            wire:target="save"
            data-testid="analytics-link-save"
        >{{ $submit }}</x-admin.ui.button>
    </x-slot:footer>
</x-admin.ui.drawer>
