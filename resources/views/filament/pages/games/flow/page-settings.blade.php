{{--
    Where a step sends the visitor into the site or beyond — Go to Page, or
    Full Result going on to a page: a category, a tag, a page of this site or
    another site, which can open in a tab of its own.
--}}
@php($destination = $step['settings']['destination'] ?? 'category')
@include('filament.pages.games.flow.choice', [
    'label' => 'Where it leads',
    'options' => ['category' => 'A category', 'tag' => 'A tag', 'page' => 'Another page on this site', 'url' => 'Another site'],
    'value' => $destination,
    'model' => $path.'.settings.destination',
])
@switch ($destination)
    @case('tag')
        <x-admin.ui.text-field label="Tag" id="rg-admin-step-tag" :value="$step['settings']['tag'] ?? ''" hint="The tag’s slug, as in its address." wire:model.live.debounce.400ms="{{ $path }}.settings.tag" />
        @break
    @case('page')
        <x-admin.ui.text-field label="Page address" id="rg-admin-step-path" :value="$step['settings']['path'] ?? ''" hint="A path on this site, e.g. /top or /posts/123." wire:model.live.debounce.400ms="{{ $path }}.settings.path" />
        @break
    @case('url')
        <x-admin.ui.text-field label="Site address" id="rg-admin-step-url" :value="$step['settings']['url'] ?? ''" hint="A full https address on another site. {click_id}, {game} and {result} can be added." wire:model.live.debounce.400ms="{{ $path }}.settings.url" />
        @break
    @default
        <label class="rg-admin-field">
            <span class="rg-admin-label">Category</span>
            <select class="rg-admin-games__select" wire:model.live="{{ $path }}.settings.category">
                <option value="">Choose a category</option>
                @foreach ($categories as $slug => $name)
                    <option value="{{ $slug }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
@endswitch
@if ($destination === 'url')
    <x-admin.ui.switch label="Open in a new tab" id="rg-admin-step-new-tab" :checked="(bool) ($step['settings']['new_tab'] ?? false)" on-hint="The game stays open behind it." off-hint="The visitor leaves the game." wire:model.live="{{ $path }}.settings.new_tab" />
@endif
