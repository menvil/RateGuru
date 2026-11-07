@php
    /*
     * Content Studio's Action queue (StudioActionQueue). The header band with
     * what waits, runs, is held and failed, and the executor's switch; one
     * card: the search over the publication, the action's id and its persona,
     * State, Kind and Publication, read on the server; the table and its
     * pages. Each row opens its details in place. Header and rows share one
     * grid; where the card is narrower than the grid, it scrolls sideways and
     * every column stays (TBL-01).
     */
    $columns = '--rg-admin-table-columns: minmax(200px, 1.1fr) minmax(220px, 1.3fr) 170px 150px 176px 130px 220px; --rg-admin-table-min-width: 1260px';
    $total = $paginator->total();
@endphp

<div class="rg-admin rg-admin-screen" x-data @if ($polling) wire:poll.{{ \App\Filament\Pages\StudioActionQueue::POLL_SECONDS }}s @endif>
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-studio-queue-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-studio-queue-title" class="rg-admin-page-header__title">Action queue</h1>
            <p class="rg-admin-page-header__description">Every action approved publications run: posts, comments, replies, votes and rating answers. Planned and actual times are kept apart.</p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Pending</dt>
                <dd class="rg-admin-stat__value">{{ $stats['pending'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Running</dt>
                <dd class="rg-admin-stat__value">{{ $stats['running'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Held</dt>
                <dd class="rg-admin-stat__value">{{ $stats['held'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Errors</dt>
                <dd class="rg-admin-stat__value">{{ $stats['failed'] }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <div class="rg-admin-studio-queue__executor" role="status">
            <span @class(['rg-admin-studio-queue__executor-dot', 'rg-admin-studio-queue__executor-dot--on' => $executorOn]) aria-hidden="true"></span>
            <span class="rg-admin-studio-queue__executor-text">
                @if ($executorOn)
                    <strong>Executor on.</strong> Each action is sent the moment it is due and what it depends on is done; the minute’s run sends whatever was missed.
                @else
                    <strong>Executor off.</strong> Nothing is sent or run: approved publications wait, and nothing is published until it is switched on.
                @endif
            </span>
            @if ($canSwitch)
                <x-admin.ui.button size="sm" icon="power" :variant="$executorOn ? 'secondary' : 'primary'" wire:click="askToSwitch('{{ $executorOn ? 'off' : 'on' }}')">{{ $executorOn ? 'Switch off' : 'Switch on' }}</x-admin.ui.button>
            @endif
        </div>

        <div class="rg-admin-table">
            <div class="rg-admin-toolbar">
                <x-admin.ui.search-field
                    class="rg-admin-toolbar__search"
                    placeholder="Search publication, action or executor"
                    name="q"
                    id="rg-admin-studio-queue-search"
                    :value="$q"
                    wire:model.live.debounce.300ms="q"
                    clearable
                />
                <x-admin.ui.filter-dropdown label="State" id="rg-admin-studio-queue-status" :options="$statuses" :value="$status" wire:model.live="status" />
                <x-admin.ui.filter-dropdown label="Kind" id="rg-admin-studio-queue-type" :options="$types" :value="$type" wire:model.live="type" />
                <x-admin.ui.filter-dropdown label="Publication" id="rg-admin-studio-queue-plan" :options="$publications" :value="$plan" wire:model.live="plan" />
                <x-admin.ui.button size="sm" :trailing-icon="$oldest ? 'arrow-up' : 'arrow-down'" wire:click="toggleOrder">Planned: {{ $oldest ? 'earliest first' : 'latest first' }}</x-admin.ui.button>
                @foreach ([['State', $statusName, 'status'], ['Kind', $typeName, 'type'], ['Publication', $planName, 'plan']] as [$label, $name, $property])
                    @if ($name !== null)
                        <span class="rg-admin-filter-chip">
                            {{ $label }}: {{ $name }}
                            <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter {{ $label }}: {{ $name }}" wire:click="$set('{{ $property }}', '')">
                                <x-admin.ui.icon name="x" :size="12" />
                            </button>
                        </span>
                    @endif
                @endforeach
                {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                <span class="rg-admin-toolbar__count" role="status">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('action', $total) }}{{ $filtered ? ($total === 1 ? ' matches' : ' match') : '' }}</span>
            </div>

            @if ($rows !== [])
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid" role="table" aria-label="Actions" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Publication</div>
                            <div class="rg-admin-table__cell" role="columnheader">Action</div>
                            <div class="rg-admin-table__cell" role="columnheader">Executor</div>
                            <div class="rg-admin-table__cell" role="columnheader">Planned</div>
                            <div class="rg-admin-table__cell" role="columnheader">Runs</div>
                            <div class="rg-admin-table__cell" role="columnheader">State</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            @include('filament.pages.studio-action-queue.row', ['row' => $row])
                        @endforeach
                    </div>
                </div>

                <x-admin.ui.pagination :paginator="$paginator" noun="actions" :note="$oldest ? 'earliest planned first' : 'latest planned first'" action="goToPage" label="Action queue pages" />
            @elseif ($empty && ! $filtered)
                <x-admin.ui.empty-state icon="list-checks" title="No actions yet">
                    Approving a publication writes its actions here: its post, then its comments, replies and votes.
                </x-admin.ui.empty-state>
            @else
                <x-admin.ui.empty-state icon="search-x" title="No actions match">
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

    @if (($confirmation['what'] ?? null) === 'cancel')
        <x-admin.ui.confirm-dialog
            id="rg-admin-studio-action-cancel"
            tone="warning"
            icon="x"
            title="Cancel this action?"
            wire:key="confirm-cancel-studio-action-{{ $confirmation['id'] }}"
            x-on:dismiss="$wire.closeConfirmation()"
        >
            <p>It is cancelled for good, and so is every action of its branch that still waits for it. Nothing already published is taken back.</p>

            <x-slot:details>
                <div class="rg-admin-dialog-subject">
                    <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="list-checks" :size="16" /></span>
                    <span class="rg-admin-dialog-subject__text">
                        <span class="rg-admin-dialog-subject__title">{{ $confirmation['title'] }}</span>
                        <span class="rg-admin-dialog-subject__meta">{{ $confirmation['plan'] }}</span>
                    </span>
                </div>
                @if ($confirmation['waiting'] > 0)
                    <ul class="rg-admin-dialog-facts">
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="git-branch" :size="16" />{{ number_format($confirmation['waiting']) }} {{ \Illuminate\Support\Str::plural('action', $confirmation['waiting']) }} directly depending on it, and whatever depends on those</li>
                    </ul>
                @endif
            </x-slot:details>

            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Keep it</x-admin.ui.button>
                <x-admin.ui.button variant="primary" wire:click="cancel" wire:loading.attr="disabled">Cancel action</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @elseif (($confirmation['what'] ?? null) === 'switch')
        <x-admin.ui.confirm-dialog
            id="rg-admin-studio-execution-switch"
            tone="warning"
            icon="power"
            :title="$confirmation['on'] ? 'Switch the executor on?' : 'Switch the executor off?'"
            wire:key="confirm-switch-studio-execution"
            x-on:dismiss="$wire.closeConfirmation()"
        >
            @if ($confirmation['on'])
                <p>Approved publications start running at once: whatever is due — a past-dated plan’s post, comments and votes among them — is published as its personas, on this site, now.</p>
            @else
                <p>Nothing more is sent or started. A run a worker already began finishes; nothing published is taken back. Switch it on again to carry on where the queue stopped.</p>
            @endif

            <x-slot:details>
                <ul class="rg-admin-dialog-facts">
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="list-checks" :size="16" />{{ number_format($confirmation['pending']) }} pending {{ \Illuminate\Support\Str::plural('action', $confirmation['pending']) }}</li>
                </ul>
            </x-slot:details>

            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="primary" icon="power" wire:click="switchExecution" wire:loading.attr="disabled">{{ $confirmation['on'] ? 'Switch on' : 'Switch off' }}</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
