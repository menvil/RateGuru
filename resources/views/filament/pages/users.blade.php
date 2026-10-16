@php
    /*
     * Users (ListUsers). The tabs, then one card: the search over the
     * username, the name and the email address, the role and the suspicious,
     * read on the server; the table and its pages. Header and rows share one
     * grid; where the card is narrower than the grid, the card scrolls
     * sideways and every column stays (TBL-01).
     */
    $columns = '--rg-admin-table-columns: minmax(200px, 1fr) 220px 110px 140px 150px 70px 80px 110px; --rg-admin-table-min-width: 1080px';
    $total = $paginator->total();
@endphp

<div class="rg-admin rg-admin-screen" x-data>
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-users-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-users-title" class="rg-admin-page-header__title">Users</h1>
            <p class="rg-admin-page-header__description">
                Status changes only through moderation actions. Trust decides whether a person’s posts skip review.
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Total</dt>
                <dd class="rg-admin-stat__value">{{ $stats['total'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Restricted</dt>
                <dd class="rg-admin-stat__value">{{ $stats['restricted'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Staff</dt>
                <dd class="rg-admin-stat__value">{{ $stats['staff'] }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <x-admin.ui.tabs label="Users by status" :active="$status" :items="$tabs" />

        <div class="rg-admin-table">
            <div class="rg-admin-toolbar">
                <x-admin.ui.search-field
                    class="rg-admin-toolbar__search"
                    placeholder="Search username, name or email"
                    name="q"
                    id="rg-admin-users-search"
                    :value="$q"
                    wire:model.live.debounce.300ms="q"
                    clearable
                />
                <x-admin.ui.filter-dropdown label="Role" id="rg-admin-users-role" :options="$roles" :value="$role" wire:model.live="role" />
                @if ($suspicious)
                    <span class="rg-admin-filter-chip">
                        Suspicious: shadowbanned or reported content
                        <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Suspicious" wire:click="$set('suspicious', false)">
                            <x-admin.ui.icon name="x" :size="12" />
                        </button>
                    </span>
                @endif
                @if ($roleName !== null)
                    <span class="rg-admin-filter-chip">
                        Role: {{ $roleName }}
                        <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Role: {{ $roleName }}" wire:click="$set('role', '')">
                            <x-admin.ui.icon name="x" :size="12" />
                        </button>
                    </span>
                @endif
                {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                <span class="rg-admin-toolbar__count" role="status">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('person', $total) }}{{ $filtered ? ' match' : '' }}</span>
            </div>

            @if ($rows !== [])
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid" role="table" aria-label="Users" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Username</div>
                            <div class="rg-admin-table__cell" role="columnheader">Email</div>
                            <div class="rg-admin-table__cell" role="columnheader">Role</div>
                            <div class="rg-admin-table__cell" role="columnheader">Status</div>
                            <div class="rg-admin-table__cell" role="columnheader">Trust</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Posts</div>
                            <div class="rg-admin-table__cell" role="columnheader">Reports</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            @include('filament.pages.users.row', ['row' => $row])
                        @endforeach
                    </div>
                </div>

                <x-admin.ui.pagination :paginator="$paginator" noun="people" note="newest first" action="goToPage" label="Users pages" />
            @else
                <x-admin.ui.empty-state icon="search-x" title="No people match">
                    Try a different search, or remove the filters.
                    @if ($filtered)
                        <x-slot:action>
                            <x-admin.ui.button size="sm" wire:click="clearFilters">Clear filters</x-admin.ui.button>
                        </x-slot:action>
                    @endif
                </x-admin.ui.empty-state>
            @endif
        </div>
    </div>

    @if ($dialog !== null)
        @include('filament.pages.users.sanction')
    @endif
</div>
