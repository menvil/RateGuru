@php
    /*
     * The Publication step's Action plan (StudioPlanActionPlan). Once the plan
     * is approved, how the publication stands over it. The plan itself: its
     * figures, the timeline of the post's part and the discussion's, the part
     * shown, and the actions 25 a page — each with when it happens, its part,
     * what it does and after what, who does it, when it runs and how it
     * stands. Header and rows share one grid, which scrolls sideways in a
     * narrow card (TBL-01).
     */
    // The prototype's columns and width (StudioPublish): Planned, Part, Action, Executor, Runs at, State.
    $columns = '--rg-admin-table-columns: 132px 100px minmax(190px, 1.3fr) 130px 150px 120px; --rg-admin-table-min-width: 800px';
@endphp

<div class="rg-admin-studio__plan" @if ($polling) wire:poll.{{ \App\Livewire\Admin\StudioPlanActionPlan::POLL_SECONDS }}s @endif>
    @if ($plan === null)
        <x-admin.ui.inline-notice tone="warning">This plan was deleted, so it has no actions.</x-admin.ui.inline-notice>
    @else
        @if ($status !== null)
            <x-admin.ui.card title="Publication status">
                <x-slot:aside>
                    <x-admin.ui.badge :tone="$status['state']['tone']" :dot="$status['state']['dot']">{{ $status['state']['label'] }}</x-admin.ui.badge>
                </x-slot:aside>

                <div class="rg-admin-studio__stack">
                    <dl class="rg-admin-detail-list">
                        @if ($status['approved'] !== null)
                            <div class="rg-admin-detail-row">
                                <x-admin.ui.icon name="clipboard-check" class="rg-admin-detail-row__icon" />
                                <dt class="rg-admin-detail-row__label">Approval</dt>
                                <dd class="rg-admin-detail-row__value">{{ $status['approved'] }}</dd>
                            </div>
                        @endif
                        <div class="rg-admin-detail-row">
                            <x-admin.ui.icon name="newspaper" class="rg-admin-detail-row__icon" />
                            <dt class="rg-admin-detail-row__label">Post</dt>
                            <dd class="rg-admin-detail-row__value">
                                {{ $status['post'] }}
                                @if ($status['postUrl'] !== null)
                                    · <a href="{{ $status['postUrl'] }}" target="_blank" rel="noopener" class="rg-admin-link">Open post</a>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if (! $status['executorOn'] && $status['open'] > 0)
                        <x-admin.ui.inline-notice tone="warning">
                            The executor is switched off, so none of these actions runs. Nothing is published until it is switched on in the Action queue.
                        </x-admin.ui.inline-notice>
                    @elseif ($polling)
                        <x-admin.ui.inline-notice tone="info">
                            Running: each action is sent the moment it is due and what it depends on is done. This list follows it as it goes.
                        </x-admin.ui.inline-notice>
                    @endif

                    <div class="rg-admin-studio__plan-actions">
                        <x-admin.ui.button size="sm" icon="list-checks" :href="$status['queueUrl']">Open in Action queue</x-admin.ui.button>
                        @if ($status['canStop'])
                            <x-admin.ui.button size="sm" icon="circle-stop" wire:click="askToStop">Stop remaining actions</x-admin.ui.button>
                        @endif
                    </div>
                </div>
            </x-admin.ui.card>
        @endif

        <x-admin.ui.card title="Action plan" :description="$data['meta']" flush>
            @if ($data['preview'])
                <x-slot:aside>
                    <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="recalculate">Recalculate</x-admin.ui.button>
                </x-slot:aside>
            @endif

            <dl class="rg-admin-studio__plan-stats">
                @foreach ($data['stats'] as $stat)
                    <div class="rg-admin-studio__plan-stat">
                        <dt class="rg-admin-studio__plan-stat-label">{{ $stat['label'] }}</dt>
                        <dd @class(['rg-admin-studio__plan-stat-value', 'rg-admin-studio__plan-stat-value--'.$stat['tone'] => $stat['tone'] !== null])>{{ number_format($stat['value']) }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($data['lanes'] !== [])
                <div class="rg-admin-studio__plan-timeline">
                    <ul class="rg-admin-studio__plan-legend" aria-label="Marks">
                        @foreach ($data['legend'] as $entry)
                            <li class="rg-admin-studio__plan-legend-item"><span class="rg-admin-studio__plan-swatch rg-admin-studio__plan-swatch--{{ $entry['tone'] }}" aria-hidden="true"></span>{{ $entry['label'] }}</li>
                        @endforeach
                    </ul>
                    @foreach ($data['lanes'] as $lane)
                        <div class="rg-admin-studio__plan-lane">
                            <span class="rg-admin-studio__plan-lane-label">{{ $lane['label'] }}</span>
                            <div class="rg-admin-studio__plan-track" aria-hidden="true">
                                @foreach ($lane['marks'] as $mark)
                                    <span class="rg-admin-studio__plan-mark rg-admin-studio__plan-swatch--{{ $mark['tone'] }}" style="left: {{ $mark['left'] }}" title="{{ $mark['title'] }}"></span>
                                @endforeach
                                @if ($data['now'] !== null)
                                    <span class="rg-admin-studio__plan-now" style="left: {{ $data['now'] }}" title="Now"></span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    <div class="rg-admin-studio__plan-axis" aria-hidden="true">
                        @foreach ($data['axis'] as $tick)
                            <span>{{ $tick }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="rg-admin-studio__plan-filter">
                <x-admin.ui.segmented label="Show part of the plan" compact :options="collect($data['parts'])->mapWithKeys(fn (array $option): array => [$option['value'] => $option['label']])->all()" :value="$data['part']" wire:model.live="part" />
                <span class="rg-admin-studio__plan-filter-note">{{ $data['preview'] ? 'Post timing is set on the Post step’s Votes tab, the discussion’s on the Discussion step.' : 'Every action, its planned time and when it really ran.' }}</span>
            </div>

            @if ($data['rows'] !== [])
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid rg-admin-studio__plan-table" role="table" aria-label="Action plan" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Planned</div>
                            <div class="rg-admin-table__cell" role="columnheader">Part</div>
                            <div class="rg-admin-table__cell" role="columnheader">Action</div>
                            <div class="rg-admin-table__cell" role="columnheader">Executor</div>
                            <div class="rg-admin-table__cell" role="columnheader">Runs at</div>
                            <div class="rg-admin-table__cell" role="columnheader">State</div>
                        </div>

                        @foreach ($data['rows'] as $row)
                            <div @class(['rg-admin-table__row', 'rg-admin-studio__plan-row--overdue' => $row['flag']]) role="row" wire:key="plan-row-{{ $row['key'] }}">
                                <div class="rg-admin-table__cell" role="cell">
                                    <span @class(['rg-admin-studio__plan-time', 'rg-admin-studio__plan-time--due' => $row['overdue']])>{{ $row['planned'] }}</span>
                                </div>
                                <div class="rg-admin-table__cell" role="cell">
                                    <span class="rg-admin-studio__plan-part"><span class="rg-admin-studio__plan-swatch rg-admin-studio__plan-swatch--part-{{ $row['partKey'] }}" aria-hidden="true"></span>{{ $row['part'] }}</span>
                                </div>
                                <div class="rg-admin-table__cell rg-admin-studio__plan-action" role="cell">
                                    <x-admin.ui.icon :name="$row['icon']" :size="14" class="rg-admin-studio__plan-action-icon" />
                                    {{-- Two lines, as the prototype's: what it does — a link to what it wrote, once that is on the site — and after what. --}}
                                    <span class="rg-admin-studio__plan-action-text">
                                        <span @class(['rg-admin-studio__plan-action-label', 'rg-admin-studio__plan-action-label--strong' => $row['strong']]) @if ($row['title'] !== null) title="{{ $row['title'] }}" @endif>
                                            @isset($row['id'])<span class="rg-admin-studio__plan-id">#{{ $row['id'] }}</span> @endisset
                                            @if ($row['links'] !== [])
                                                <a href="{{ $row['links'][0]['url'] }}" target="_blank" rel="noopener" class="rg-admin-link" title="{{ $row['links'][0]['label'] }}">{{ $row['action'] }}</a>
                                            @else
                                                {{ $row['action'] }}
                                            @endif
                                        </span>
                                        <span class="rg-admin-studio__plan-sub">{{ $row['after'] }}</span>
                                    </span>
                                </div>
                                <div class="rg-admin-table__cell" role="cell">
                                    <span class="rg-admin-studio__plan-executor">{{ $row['executor'] }}</span>
                                </div>
                                <div class="rg-admin-table__cell rg-admin-studio__plan-runs" role="cell">
                                    <span class="rg-admin-studio__plan-time">{{ $row['runs'] }}</span>
                                    @if ($row['runsNote'] !== null)
                                        <span class="rg-admin-studio__plan-sub">{{ $row['runsNote'] }}</span>
                                    @endif
                                </div>
                                <div @class(['rg-admin-table__cell', 'rg-admin-studio__plan-state', 'rg-admin-studio__plan-state--cancelled' => $row['state']['label'] === 'Cancelled']) role="cell">
                                    <x-admin.ui.badge :tone="$row['state']['tone']" :dot="$row['state']['dot']">{{ $row['state']['label'] }}</x-admin.ui.badge>
                                    @if ($row['flag'])
                                        <span class="rg-admin-studio__plan-overdue">Overdue</span>
                                    @endif
                                    @if ($row['reason'] !== null)
                                        <span class="rg-admin-studio__plan-sub">{{ $row['reason'] }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <x-admin.ui.pagination :paginator="$data['paginator']" noun="actions" note="by planned time" action="goToPage" label="Action plan pages" />
            @else
                <x-admin.ui.empty-state icon="list-checks" title="No actions in this part">
                    Choose All to see every action of the plan.
                </x-admin.ui.empty-state>
            @endif

            <p class="rg-admin-studio__plan-note">{{ $data['note'] }}</p>
        </x-admin.ui.card>
    @endif

    @if ($confirmingStop && $status !== null)
        <x-admin.ui.confirm-dialog
            id="rg-admin-studio-plan-stop"
            tone="warning"
            icon="circle-stop"
            title="Stop the remaining actions?"
            wire:key="confirm-stop-studio-plan-{{ $planId }}"
            x-on:dismiss="$wire.closeConfirmation()"
        >
            <p>Every action of this plan not yet run is cancelled for good. Nothing already published is taken back: the post, and the comments and votes already written, stay. A run already under way finishes.</p>

            <x-slot:details>
                <ul class="rg-admin-dialog-facts">
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="list-checks" :size="16" />{{ number_format($status['open']) }} {{ \Illuminate\Support\Str::plural('action', $status['open']) }} not yet run: waiting, held, failed or sent</li>
                </ul>
            </x-slot:details>

            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Keep them</x-admin.ui.button>
                <x-admin.ui.button variant="primary" icon="circle-stop" wire:click="stop" wire:loading.attr="disabled">Stop remaining actions</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
