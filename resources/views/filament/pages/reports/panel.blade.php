@php
    /*
     * The open report's chain (DOM-02) in the 448 panel: the report — its
     * reason, who reported it and what they wrote, the other open reports on
     * the same target — then the target with Open target and Hide target,
     * then the target's author with View user and Ban author. Its footer
     * closes the report with an optional note, or says how it was closed
     * and reopens it.
     *
     * Docked beside the list where there is room for both (sticky as the
     * page scrolls); otherwise over the list on the right, closed with its
     * close button or Escape.
     */
    use App\Filament\Resources\Reports\Pages\ListReports;

    $target = $detail['target'];
    $author = $detail['author'];
    $isPerson = $target['kind'] === 'Person';
@endphp

<aside
    id="rg-admin-report-panel"
    class="rg-admin-reports__panel"
    aria-labelledby="rg-admin-report-panel-title"
    wire:key="report-panel-{{ $detail['id'] }}"
    x-on:keydown.escape="if (! wide) $wire.closePanel()"
>
    <div class="rg-admin-card rg-admin-reports__card">
        <div class="rg-admin-reports__head">
            <div class="rg-admin-reports__head-text">
                <h2 id="rg-admin-report-panel-title" class="rg-admin-reports__title">Report #{{ $detail['id'] }}</h2>
                <p class="rg-admin-reports__subtitle">{{ $detail['created'] }}</p>
            </div>
            <x-admin.ui.badge :tone="$detail['status']['tone']">{{ $detail['status']['label'] }}</x-admin.ui.badge>
            <x-admin.ui.icon-button icon="chevron-up" label="Previous report" variant="ghost" size="sm" wire:click="step(-1)" />
            <x-admin.ui.icon-button icon="chevron-down" label="Next report" variant="ghost" size="sm" wire:click="step(1)" />
            <x-admin.ui.icon-button icon="x" label="Close details" variant="ghost" size="sm" wire:click="closePanel" />
        </div>

        <div class="rg-admin-reports__chain">
            <x-admin.ui.timeline-item icon="flag" :title="$detail['reason']" :time="$detail['short']">
                @if ($detail['reporter'] !== null)
                    <div class="rg-admin-reports__reporter">
                        <span class="rg-admin-avatar" aria-hidden="true">{{ $detail['reporter']['initials'] }}</span>
                        <span>Reported by <strong class="rg-admin-reports__strong">{{ $detail['reporter']['name'] }}</strong></span>
                    </div>
                @endif
                @if ($detail['message'] !== null)
                    <blockquote class="rg-admin-reports__message">“{{ $detail['message'] }}”</blockquote>
                @endif
                @if ($detail['siblings'] !== null)
                    <p class="rg-admin-reports__note">{{ $detail['siblings'] }}</p>
                @endif
            </x-admin.ui.timeline-item>

            @unless ($isPerson)
                <x-admin.ui.timeline-item :icon="$target['icon']" :title="$target['title']">
                    <div class="rg-admin-reports__subject">
                        <span @class(['rg-admin-reports__subject-tile', 'rg-admin-reports__subject-tile--round' => $target['kind'] === 'Comment']) aria-hidden="true"><x-admin.ui.icon :name="$target['icon']" :size="18" /></span>
                        <span class="rg-admin-table__stack">
                            <span class="rg-admin-reports__subject-text">{{ $target['preview'] }}</span>
                            @if ($target['meta'] !== null)
                                <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $target['meta'] }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="rg-admin-reports__actions">
                        @if ($target['state'] !== null)
                            <x-admin.ui.badge :tone="$target['state']['tone']" :dot="$target['state']['dot']">{{ $target['state']['label'] }}</x-admin.ui.badge>
                        @endif
                        <span class="rg-admin-reports__spacer"></span>
                        @if ($target['url'] !== null)
                            <x-admin.ui.button size="sm" icon="arrow-up-right" :href="$target['url']" target="_blank" rel="noopener">Open target</x-admin.ui.button>
                        @endif
                        @if ($target['canHide'])
                            <x-admin.ui.button size="sm" icon="eye-off" wire:click="askToHideTarget">Hide target</x-admin.ui.button>
                        @endif
                    </div>
                    @if ($target['hiddenNotice'])
                        <x-admin.ui.inline-notice tone="info">The target is hidden, but this report stays open until you resolve or ignore it.</x-admin.ui.inline-notice>
                    @endif
                </x-admin.ui.timeline-item>
            @endunless

            @if ($author !== null)
                <x-admin.ui.timeline-item :initials="$author['initials']" :title="($isPerson ? 'Person · ' : 'Author · ').$author['name']" last>
                    <div class="rg-admin-reports__actions">
                        <x-admin.ui.badge :tone="$author['status']['tone']">{{ $author['status']['label'] }}</x-admin.ui.badge>
                        <x-admin.ui.badge tone="outline">{{ $author['trust'] }}</x-admin.ui.badge>
                        <span class="rg-admin-reports__note">{{ $author['stats'] }}</span>
                    </div>
                    <div class="rg-admin-reports__actions">
                        @if ($author['url'] !== null)
                            <x-admin.ui.button size="sm" icon="user" :href="$author['url']">View user</x-admin.ui.button>
                        @endif
                        @if ($isPerson && $target['url'] !== null)
                            <x-admin.ui.button size="sm" icon="arrow-up-right" :href="$target['url']" target="_blank" rel="noopener">Open profile</x-admin.ui.button>
                        @endif
                        <x-admin.ui.button size="sm" icon="ban" :disabled="$author['ban'] !== null" wire:click="askToBan" :aria-describedby="$author['ban'] !== null ? 'rg-admin-report-ban-reason' : null">Ban {{ $isPerson ? 'user' : 'author' }}</x-admin.ui.button>
                        @if ($author['ban'] !== null)
                            <span id="rg-admin-report-ban-reason" class="rg-admin-hint">{{ $author['ban'] }}</span>
                        @endif
                    </div>
                </x-admin.ui.timeline-item>
            @else
                <x-admin.ui.timeline-item icon="circle-x" title="Author unavailable" last>
                    <p class="rg-admin-reports__note">The reported content or its author is no longer there.</p>
                </x-admin.ui.timeline-item>
            @endif
        </div>

        <div class="rg-admin-reports__footer">
            @if ($detail['open'])
                <x-admin.ui.textarea
                    label="Resolution note"
                    id="rg-admin-report-note"
                    :value="$note"
                    :required="false"
                    :limit="ListReports::NOTE_LIMIT"
                    :rows="2"
                    placeholder="What did you decide and why…"
                    hint="Stored with the report."
                    :error="$errors->first('note') ?: null"
                    wire:model="note"
                />
                <div class="rg-admin-reports__close">
                    <span class="rg-admin-reports__close-note">Closes this report only. The next open report opens.</span>
                    <x-admin.ui.button wire:click="ignore" wire:loading.attr="disabled">Ignore</x-admin.ui.button>
                    <x-admin.ui.button variant="primary" icon="check" wire:click="resolve" wire:loading.attr="disabled">Resolve</x-admin.ui.button>
                </div>
            @else
                <div class="rg-admin-reports__closed">
                    <div class="rg-admin-table__stack">
                        <span class="rg-admin-reports__closed-title">{{ $detail['closed']['title'] }}</span>
                        <span class="rg-admin-reports__note">{{ $detail['closed']['note'] }}</span>
                    </div>
                    <x-admin.ui.button size="sm" icon="rotate-ccw" wire:click="reopen({{ $detail['id'] }})" wire:loading.attr="disabled">Reopen</x-admin.ui.button>
                </div>
            @endif
        </div>
    </div>
</aside>
