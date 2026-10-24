{{--
    Step 4 · Publication. When the post is to be published — once the plan
    is approved, or at a date it will carry as its own — and the publication
    card as the draft stands now, saved or not. Saving the draft publishes
    and schedules nothing, and there is nothing here to confirm.
--}}
<div class="rg-admin-studio__columns">
    <x-admin.ui.card title="Publication mode">
        <div class="rg-admin-studio__stack">
            <x-admin.ui.radio-cards label="Publication mode" :options="$modes" :value="$publicationMode" wire:model.live="publicationMode" />
            @error('publicationMode')
                <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
            @enderror

            @if ($publicationMode === 'at_date')
                <x-admin.ui.date-time-field
                    label="Publication date and time"
                    id="rg-admin-studio-publish-at"
                    :value="$publishAt"
                    :min="$limits['earliest']"
                    :max="$limits['latest']"
                    :timezone="$timezone"
                    :required="false"
                    :hint="'Any date from 1970 to 2038, past or future, in '.$timezone.'.'"
                    :error="$errors->first('publishAt') ?: null"
                    wire:model.live="publishAt"
                />
                @if (trim($publishAt) === '' && ! $errors->has('publishAt'))
                    <x-admin.ui.inline-notice tone="warning">Publication date not selected</x-admin.ui.inline-notice>
                @endif
            @endif
        </div>
    </x-admin.ui.card>

    <x-admin.ui.card title="Publication card" description="The post as this draft would make it, saved or not.">
        <div class="rg-admin-studio__stack">
            <div class="rg-admin-studio__card">
                <span class="rg-admin-studio__card-image" aria-hidden="true">
                    @if ($card['image'] !== null)
                        <img src="{{ $card['image'] }}" alt="" class="rg-admin-studio__preview-image" />
                    @else
                        <x-admin.ui.icon name="image" :size="24" />
                    @endif
                </span>
                <div class="rg-admin-studio__card-text">
                    <p @class(['rg-admin-studio__card-title', 'rg-admin-studio__empty' => $card['title'] === ''])>{{ $card['title'] !== '' ? $card['title'] : 'No title yet' }}</p>
                    <p @class(['rg-admin-studio__card-description', 'rg-admin-studio__empty' => $card['description'] === ''])>{{ $card['description'] !== '' ? $card['description'] : 'No description yet' }}</p>
                </div>
            </div>
            <dl class="rg-admin-detail-list">
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="image" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Photo</dt>
                    <dd class="rg-admin-detail-row__value">{{ $card['image'] !== null ? 'Chosen' : 'No photo yet' }}</dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="user" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Author</dt>
                    <dd class="rg-admin-detail-row__value">{{ $card['author'] !== null ? $card['author']['name'].($card['author']['username'] !== null ? ' · @'.$card['author']['username'] : '') : 'No author selected' }}</dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="folder" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Category</dt>
                    <dd class="rg-admin-detail-row__value">{{ $card['category'] ?? 'No category' }}</dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="calendar" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Publication date</dt>
                    <dd class="rg-admin-detail-row__value">{{ $card['when'] }}</dd>
                </div>
            </dl>
            <x-admin.ui.inline-notice tone="info">This is a draft. Saving it does not publish or schedule anything.</x-admin.ui.inline-notice>
        </div>
    </x-admin.ui.card>
</div>
