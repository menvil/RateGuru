{{-- Logs: clicks, milestones, postbacks and blocking, newest first, fifty to a page. --}}
<x-admin.ui.segmented label="Log" :options="['clicks' => 'Clicks', 'milestones' => 'Milestones', 'postbacks' => 'Postbacks', 'blocks' => 'Blocks']" :value="$log" wire:model.live="log" />

<div class="rg-admin-traffic__log" data-testid="traffic-log-{{ $log }}">
    @forelse ($logPage->items() as $row)
        <div class="rg-admin-traffic__log-row" wire:key="log-{{ $log }}-{{ $row->id }}">
            @switch ($log)
                @case('milestones')
                    <span>{{ $row->occurred_at->format('M j, H:i:s') }}</span>
                    <span>{{ $labels['source_id'][(string) $row->source_id] ?? '' }}</span>
                    <span>{{ str_replace('_', ' ', $row->event->value) }}{{ $row->goal !== null ? ' · goal '.$row->goal : '' }}</span>
                    <span>{{ $money($row->value, 4) }} {{ $row->value_basis->value }}</span>
                    <span>{{ $row->postback_skip?->value ?? '' }}</span>
                    @break
                @case('postbacks')
                    <span>{{ $row->created_at->format('M j, H:i:s') }}</span>
                    <span>{{ $labels['source_id'][(string) $row->source_id] ?? '' }} · {{ str_replace('_', ' ', $row->event->value) }}</span>
                    <x-admin.ui.badge :tone="match ($row->status->value) { 'delivered' => 'success', 'failed', 'expired' => 'danger', 'pending', 'sending' => 'warning', default => 'neutral' }">{{ $row->status->value }}</x-admin.ui.badge>
                    <span>{{ $row->outcome?->value }}{{ $row->http_status !== null ? ' · HTTP '.$row->http_status : '' }} · {{ $row->attempts }} {{ \Illuminate\Support\Str::plural('try', $row->attempts) }}</span>
                    @if (in_array($row->status->value, ['failed', 'expired', 'cancelled'], true))
                        <x-admin.ui.button size="sm" wire:click="resend({{ $row->id }})">Resend</x-admin.ui.button>
                    @endif
                    @break
                @case('blocks')
                    <span>{{ $row->occurred_at->format('M j, H:i:s') }}</span>
                    <span>{{ $labels['source_id'][(string) $row->source_id] ?? '' }}</span>
                    <span>{{ $row->kind }} {{ $row->ref_count }} {{ $row->level->value }}{{ $row->ref_count === 1 ? ' '.$row->ref : 's' }}</span>
                    <span>{{ $row->note }}</span>
                    @break
                @default
                    <span>{{ $row->arrived_at->format('M j, H:i:s') }}</span>
                    <span>{{ $row->source_id === null ? ucfirst($row->channel->value) : ($labels['source_id'][(string) $row->source_id] ?? '') }}</span>
                    <span>{{ $row->device->value }} · {{ $row->country ?? '—' }}{{ $row->city !== null ? ', '.$row->city : '' }}</span>
                    <span class="rg-admin-traffic__mono">{{ $row->detail?->landing_path }}</span>
                    <span>{{ $row->opened_at !== null ? 'opened' : 'not opened' }}{{ $row->known ? ' · known' : '' }}{{ $row->account_at !== null ? ' · account' : '' }}</span>
            @endswitch
        </div>
    @empty
        <p class="rg-admin-traffic__note">Nothing in this log yet.</p>
    @endforelse
</div>

<div class="rg-admin-traffic__row">
    @if ($pageNumber > 1)
        <x-admin.ui.button size="sm" wire:click="goToPage({{ $pageNumber - 1 }})">Newer</x-admin.ui.button>
    @endif
    @if ($logPage->hasMorePages())
        <x-admin.ui.button size="sm" wire:click="goToPage({{ $pageNumber + 1 }})">Older</x-admin.ui.button>
    @endif
</div>
