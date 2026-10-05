@php
    /*
     * The four states of one target-language field, plus the two errors that
     * block Save. Static specimens: nothing here reads or writes a translation.
     */
    $states = [
        [
            'label' => 'Saved', 'tone' => 'success', 'dot' => true, 'note' => 'Stored translation',
            'field' => 'Tagline', 'value' => 'Оценявайте домашните любимци на интернет', 'limit' => 60,
            'fieldTone' => 'default', 'generated' => false, 'error' => null, 'actions' => [],
        ],
        [
            'label' => 'Missing', 'tone' => 'warning', 'dot' => false, 'note' => 'Visitors see the English text: “Fresh pets”',
            'field' => 'Feed title', 'value' => '', 'limit' => 32,
            'fieldTone' => 'default', 'generated' => false, 'error' => null, 'actions' => ['ai'],
        ],
        [
            'label' => 'AI suggestion · not saved', 'tone' => 'info', 'dot' => true, 'note' => 'Generated 09:38 · AI suggestions are drafts until saved.',
            'field' => 'Category name', 'value' => 'Зайци и гризачи', 'limit' => 32,
            'fieldTone' => 'info', 'generated' => true, 'error' => null, 'actions' => ['discard', 'regenerate', 'save'],
        ],
        [
            'label' => 'Edited · not saved', 'tone' => 'outline', 'dot' => false, 'note' => 'Saved version is kept until you save',
            'field' => 'Rating group label', 'value' => 'Нрав', 'limit' => 16,
            'fieldTone' => 'changed', 'generated' => false, 'error' => null, 'actions' => ['discard', 'save'],
        ],
        [
            'label' => 'Edited · not saved', 'tone' => 'outline', 'dot' => false, 'note' => 'Placeholders must survive translation',
            'field' => 'Contact page', 'value' => 'Въпроси и партньорство: пишете ни. Отговаряме до два работни дни.', 'limit' => 2000,
            'fieldTone' => 'changed', 'generated' => false, 'error' => 'Keep {contact_email}', 'actions' => ['discard', 'save'],
        ],
        [
            'label' => 'AI suggestion · not saved', 'tone' => 'info', 'dot' => true, 'note' => 'Generated 09:38 · AI suggestions are drafts until saved.',
            'field' => 'Upload CTA label', 'value' => 'Добавете домашния си любимец', 'limit' => 24,
            'fieldTone' => 'info', 'generated' => true, 'error' => '4 over the limit', 'actions' => ['discard', 'regenerate', 'save'],
        ],
    ];
@endphp

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['DOM-01']])
    <div class="rg-admin-kit__stack" style="gap: 16px">
        <div class="rg-admin-kit__frame rg-admin-kit__frame--clip">
            <x-admin.ui.inline-notice tone="info" icon="sparkles" strip>
                2 AI suggestions and 2 edits not saved yet. Nothing changes for visitors until you save.
                <x-slot:actions>
                    <x-admin.ui.button variant="ghost" size="sm">Discard all</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
            <div class="rg-admin-toolbar" style="border-bottom: 0">
                <span class="rg-admin-table__meta">Translation fields · Български · bg</span>
                <span class="rg-admin-toolbar__count">Save all generated (2) lives in the top bar</span>
            </div>
        </div>

        <div class="rg-admin-kit__grid rg-admin-kit__grid--wide">
            @foreach ($states as $index => $state)
                <div @class(['rg-admin-translation-state', 'rg-admin-translation-state--generated' => $state['generated']])>
                    <div class="rg-admin-translation-state__head">
                        <x-admin.ui.badge :tone="$state['tone']" :dot="$state['dot']">{{ $state['label'] }}</x-admin.ui.badge>
                    </div>
                    <x-admin.ui.textarea
                        :label="$state['field']"
                        :id="'kit-translation-'.$index"
                        :value="$state['value']"
                        :limit="$state['limit']"
                        :tone="$state['fieldTone']"
                        :error="$state['error']"
                        :rows="2"
                        placeholder="Missing · type a translation or use AI translate"
                        compact
                    />
                    <p class="rg-admin-translation-state__note">{{ $state['note'] }}</p>
                    @if ($state['actions'] !== [])
                        <div class="rg-admin-translation-state__foot">
                            <div class="rg-admin-translation-state__actions">
                                @if (in_array('discard', $state['actions'], true))
                                    <x-admin.ui.button variant="ghost" size="sm">Discard</x-admin.ui.button>
                                @endif
                                @if (in_array('ai', $state['actions'], true))
                                    <x-admin.ui.button size="sm" icon="sparkles">AI translate</x-admin.ui.button>
                                @endif
                                @if (in_array('regenerate', $state['actions'], true))
                                    <x-admin.ui.button size="sm" icon="sparkles">Regenerate</x-admin.ui.button>
                                @endif
                                @if (in_array('save', $state['actions'], true))
                                    <x-admin.ui.button size="sm" variant="primary" :disabled="$state['error'] !== null">Save</x-admin.ui.button>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endcomponent
