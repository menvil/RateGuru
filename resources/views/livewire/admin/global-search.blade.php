{{--
    The sidebar search: Filament's global search in Admin v2 markup. Results
    open as the user types; Down moves into them and Up / Down move between
    them, Enter in the field opens the first one, Escape closes the list.
--}}
<div
    class="rg-admin-global-search"
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:open-global-search-results.window="open = true"
>
    <label class="rg-admin-search" for="rg-admin-search">
        <x-admin.ui.icon name="search" :size="18" class="rg-admin-search__icon" />
        <input
            id="rg-admin-search"
            type="search"
            class="rg-admin-search__control"
            placeholder="Search posts, users"
            aria-label="Search the admin"
            aria-keyshortcuts="Meta+K Control+K"
            aria-controls="rg-admin-search-results"
            autocomplete="off"
            maxlength="1000"
            wire:model.live.debounce.{{ $debounce }}="search"
            x-on:focus="open = true"
            x-on:keydown.down.prevent="$root.querySelector('.rg-admin-search-result')?.focus()"
            x-on:keydown.enter.prevent="$root.querySelector('.rg-admin-search-result')?.click()"
            x-on:keydown.escape="if (open && $root.querySelector('.rg-admin-search-results')) { $event.stopPropagation(); open = false } else { $el.blur() }"
        />
        <kbd
            class="rg-admin-kbd"
            aria-hidden="true"
            x-data
            x-text="/Mac|iPhone|iPad/.test(navigator.platform) ? '⌘K' : 'Ctrl K'"
        >⌘K</kbd>
    </label>

    @if ($rows !== null)
        <div
            id="rg-admin-search-results"
            class="rg-admin-search-results"
            x-cloak
            x-show="open"
            x-on:keydown.down.prevent="$focus.wrap().next()"
            x-on:keydown.up.prevent="$focus.wrap().previous()"
            x-on:keydown.escape="$event.stopPropagation(); open = false; document.getElementById('rg-admin-search')?.focus()"
        >
            @if ($rows === [])
                <p class="rg-admin-search-results__empty">Nothing in the admin matches “{{ $this->search }}”.</p>
            @else
                <ul class="rg-admin-search-results__list">
                    @foreach ($rows as $row)
                        <li>
                            <a
                                href="{{ $row['url'] }}"
                                @class(['rg-admin-search-result', 'rg-admin-search-result--first' => $loop->first])
                                x-on:click="open = false"
                            >
                                <span class="rg-admin-search-result__icon" aria-hidden="true">
                                    <x-admin.ui.icon :name="$row['icon']" :size="14" />
                                </span>
                                <span class="rg-admin-search-result__text">
                                    <span class="rg-admin-search-result__title">{{ $row['title'] }}</span>
                                    <span class="rg-admin-search-result__meta">{{ $row['meta'] }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
