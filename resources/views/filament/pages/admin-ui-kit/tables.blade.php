@php
    $columns = '--rg-admin-table-columns: 44px 56px minmax(200px, 1fr) 160px 140px 80px 120px 192px; --rg-admin-table-min-width: 992px';

    $rows = [
        [
            'selected' => true, 'title' => 'Luna howling along to the vacuum cleaner', 'meta' => 'P-48213 · Dogs',
            'initials' => 'LU', 'author' => 'luna.the.husky', 'trust' => 'New · 1 post',
            'status' => 'Pending', 'tone' => 'warning', 'dot' => false, 'note' => null,
            'reports' => 0, 'created' => 'Today 06:29', 'age' => 'Waiting 3 h 12 min', 'due' => true,
            'actions' => ['Approve', 'Reject'],
        ],
        [
            'selected' => false, 'title' => 'Rex at the vet — please be kind', 'meta' => 'P-48166 · Dogs',
            'initials' => 'IV', 'author' => 'ivan.petrov', 'trust' => 'Regular · 31 posts',
            'status' => 'Published', 'tone' => 'success', 'dot' => true, 'note' => null,
            'reports' => 3, 'created' => 'Yesterday 21:40', 'age' => null, 'due' => false,
            'actions' => ['Hide'],
        ],
        [
            'selected' => false, 'title' => 'Free kittens, DM for price', 'meta' => 'P-48090 · Cats',
            'initials' => 'CH', 'author' => 'cheap_pet_meds', 'trust' => 'New · 14 posts',
            'status' => 'Hidden', 'tone' => 'neutral', 'dot' => false, 'note' => 'Restorable until 16 Oct',
            'reports' => 4, 'created' => '02 Oct 22:47', 'age' => null, 'due' => false,
            'actions' => ['Restore'],
        ],
    ];
@endphp

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['TBL-01']])
    <div class="rg-admin-table">
        <div class="rg-admin-toolbar">
            <x-admin.ui.search-field class="rg-admin-toolbar__search" placeholder="Search title, author or post ID" name="kit-table-search" />
            <x-admin.ui.button trailing-icon="chevron-down">Category: Dogs</x-admin.ui.button>
            <x-admin.ui.button trailing-icon="chevron-down">Sort: Oldest first</x-admin.ui.button>
            <span class="rg-admin-filter-chip">
                Category: Dogs
                <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Category: Dogs">
                    <x-admin.ui.icon name="x" :size="12" />
                </button>
            </span>
            <span class="rg-admin-toolbar__count">3 posts match</span>
        </div>

        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid" role="table" aria-label="Posts" style="{{ $columns }}">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    <div class="rg-admin-table__cell" role="columnheader">
                        <input type="checkbox" class="rg-admin-checkbox" aria-label="Select all posts" />
                    </div>
                    <div class="rg-admin-table__cell" role="columnheader">Image</div>
                    <div class="rg-admin-table__cell" role="columnheader">Title</div>
                    <div class="rg-admin-table__cell" role="columnheader">Author</div>
                    <div class="rg-admin-table__cell" role="columnheader">Status</div>
                    <div class="rg-admin-table__cell" role="columnheader">Reports</div>
                    <div class="rg-admin-table__cell" role="columnheader">Created</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                </div>

                @foreach ($rows as $row)
                    <div @class(['rg-admin-table__row', 'rg-admin-table__row--selected' => $row['selected']]) role="row">
                        <div class="rg-admin-table__cell" role="cell">
                            <input type="checkbox" class="rg-admin-checkbox" aria-label="Select {{ $row['title'] }}" @checked($row['selected']) />
                        </div>
                        <div class="rg-admin-table__cell" role="cell">
                            <span class="rg-admin-table__thumb"><x-admin.ui.icon name="image" :size="18" /></span>
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
                            <div class="rg-admin-table__primary">{{ $row['title'] }}</div>
                            <div class="rg-admin-table__meta">{{ $row['meta'] }}</div>
                        </div>
                        <div class="rg-admin-table__cell" role="cell">
                            <div class="rg-admin-table__person">
                                <span class="rg-admin-avatar" aria-hidden="true">{{ $row['initials'] }}</span>
                                <div>
                                    <div>{{ $row['author'] }}</div>
                                    <div class="rg-admin-table__meta rg-admin-table__meta--small">{{ $row['trust'] }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="rg-admin-table__cell" role="cell">
                            <div class="rg-admin-badge-note">
                                <x-admin.ui.badge :tone="$row['tone']" :dot="$row['dot']">{{ $row['status'] }}</x-admin.ui.badge>
                                @if ($row['note'])
                                    <span class="rg-admin-badge-note__text">{{ $row['note'] }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="rg-admin-table__cell" role="cell">
                            @if ($row['reports'] > 0)
                                <x-admin.ui.badge :tone="$row['reports'] >= 3 ? 'danger' : 'warning'" icon="flag">{{ $row['reports'] }}<span class="rg-admin-sr-only"> reports</span></x-admin.ui.badge>
                            @else
                                <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">No reports</span></span>
                            @endif
                        </div>
                        <div class="rg-admin-table__cell" role="cell">
                            <div>{{ $row['created'] }}</div>
                            @if ($row['age'])
                                <div @class(['rg-admin-table__meta', 'rg-admin-table__meta--small', 'rg-admin-table__meta--due' => $row['due']])>{{ $row['age'] }}</div>
                            @endif
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
                            @foreach ($row['actions'] as $action)
                                <x-admin.ui.button size="sm">{{ $action }}</x-admin.ui.button>
                            @endforeach
                            <x-admin.ui.icon-button icon="ellipsis" label="More actions for {{ $row['title'] }}" variant="ghost" size="sm" />
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <nav class="rg-admin-pagination" aria-label="Posts pagination">
            <span class="rg-admin-pagination__range">1–3 of 3</span>
            <ul class="rg-admin-pagination__pages">
                <li><x-admin.ui.icon-button icon="chevron-left" label="Previous page" variant="ghost" size="sm" disabled /></li>
                <li><a class="rg-admin-pagination__page" href="#TBL-01" aria-current="page">1</a></li>
                <li><x-admin.ui.icon-button icon="chevron-right" label="Next page" variant="ghost" size="sm" disabled /></li>
            </ul>
        </nav>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['TBL-02']])
    <div class="rg-admin-kit__stack">
        <div class="rg-admin-toolbar rg-admin-kit__frame">
            <x-admin.ui.search-field class="rg-admin-toolbar__search" placeholder="Search source, key or translation" name="kit-toolbar-search" />
            <x-admin.ui.button trailing-icon="chevron-down">Section: All</x-admin.ui.button>
            <span class="rg-admin-toolbar__count">16 items on this page</span>
        </div>
        <div class="rg-admin-toolbar rg-admin-kit__frame">
            <x-admin.ui.search-field class="rg-admin-toolbar__search" placeholder="Search language or locale code" name="kit-toolbar-languages" />
            <span class="rg-admin-toolbar__count">4 of 4 installed</span>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['TBL-04']])
    <div class="rg-admin-kit__row" style="align-items: flex-start">
        <div class="rg-admin-kit__stack">
            <div class="rg-admin-kit__wrap">
                <x-admin.ui.button size="sm">Approve</x-admin.ui.button>
                <x-admin.ui.button size="sm">Reject</x-admin.ui.button>
                <x-admin.ui.icon-button icon="ellipsis" label="More actions" variant="ghost" size="sm" />
            </div>
            <span class="rg-admin-kit__caption">pending</span>
            <div class="rg-admin-kit__wrap">
                <x-admin.ui.button size="sm">Hide</x-admin.ui.button>
                <x-admin.ui.icon-button icon="ellipsis" label="More actions" variant="ghost" size="sm" />
            </div>
            <span class="rg-admin-kit__caption">published</span>
            <div class="rg-admin-kit__wrap rg-admin-table__meta" style="align-items: center; gap: 6px" title="Removal finalized. No further actions.">
                <x-admin.ui.icon name="lock" :size="14" />
                Final
            </div>
            <span class="rg-admin-kit__caption">removal finalized</span>
        </div>

        <div class="rg-admin-menu">
            <div class="rg-admin-menu__item">
                <x-admin.ui.icon name="arrow-up-right" />
                <span class="rg-admin-menu__text">Open public post</span>
            </div>
            <div class="rg-admin-menu__item">
                <x-admin.ui.icon name="user" />
                <span class="rg-admin-menu__text">View author</span>
            </div>
            <div class="rg-admin-menu__separator" aria-hidden="true"></div>
            <div class="rg-admin-menu__item rg-admin-menu__item--disabled">
                <x-admin.ui.icon name="trash-2" />
                <span class="rg-admin-menu__text">Finalize removal<span class="rg-admin-menu__hint">Unavailable · hide the post first</span></span>
            </div>
            <div class="rg-admin-menu__item rg-admin-menu__item--danger">
                <x-admin.ui.icon name="ban" />
                <span class="rg-admin-menu__text">Ban author<span class="rg-admin-menu__hint">Reversible with Restore access</span></span>
            </div>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['TBL-05']])
    <div class="rg-admin-kit__grid rg-admin-kit__grid--wide" style="gap: 16px">
        <x-admin.ui.card flush>
            <x-admin.ui.empty-state icon="circle-check" tone="success" title="The queue is clear" :heading-level="4">
                Every submitted post has been reviewed. New submissions appear here automatically.
            </x-admin.ui.empty-state>
        </x-admin.ui.card>
        <x-admin.ui.card flush>
            <x-admin.ui.empty-state icon="search-x" title="No posts match these filters" :heading-level="4">
                Try a different search or remove the category filter.
                <x-slot:action>
                    <x-admin.ui.button size="sm">Clear filters</x-admin.ui.button>
                </x-slot:action>
            </x-admin.ui.empty-state>
        </x-admin.ui.card>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['TBL-06']])
    {{-- The same grid as TBL-01, so every placeholder sits in the column it stands in for. --}}
    <div class="rg-admin-table" aria-busy="true">
        <span class="rg-admin-sr-only">Loading posts…</span>
        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid" style="{{ $columns }}">
                @foreach (['62%', '48%', '70%'] as $width)
                    <div class="rg-admin-table__row" aria-hidden="true">
                        <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="18px" height="18px" shape="box" tone="soft" /></div>
                        <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="40px" height="40px" shape="box" tone="soft" /></div>
                        <div class="rg-admin-table__cell rg-admin-kit__stack" style="gap: 8px">
                            <x-admin.ui.skeleton :width="$width" height="10px" />
                            <x-admin.ui.skeleton width="30%" height="8px" tone="soft" />
                        </div>
                        <div class="rg-admin-table__cell rg-admin-kit__stack" style="gap: 8px">
                            <x-admin.ui.skeleton width="70%" height="10px" />
                            <x-admin.ui.skeleton width="45%" height="8px" tone="soft" />
                        </div>
                        <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="72px" height="22px" shape="box" tone="soft" /></div>
                        <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="32px" height="22px" shape="box" tone="soft" /></div>
                        <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="80%" height="10px" /></div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--end"><x-admin.ui.skeleton width="120px" height="32px" shape="control" tone="soft" /></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endcomponent
