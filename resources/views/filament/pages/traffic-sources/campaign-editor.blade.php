{{-- A campaign: its stage, set at once; the rest a draft until Save; its stages' figures and its history. --}}
@php($model = $campaign['model'])
<x-admin.ui.drawer id="rg-admin-traffic-campaign" editor :title="$model->name ?? 'Campaign '.$model->external_id" :subtitle="$campaign['source'].' · ID '.$model->external_id" wire:key="campaign-{{ $model->id }}" x-on:dismiss="$wire.closeCampaign()">
    <div class="rg-admin-traffic__drawer">
    <div class="rg-admin-traffic__section">
        <span class="rg-admin-label">Stage</span>
        <div class="rg-admin-traffic__stages">
            @foreach (\App\Enums\TrafficCampaignStage::cases() as $stage)
                <x-admin.ui.button size="sm" :variant="$model->stage === $stage ? 'primary' : 'secondary'" wire:click="setStage('{{ $stage->value }}')">{{ ucfirst($stage->value) }}</x-admin.ui.button>
            @endforeach
        </div>
    </div>
    <div class="rg-admin-traffic__section">
        <x-admin.ui.text-field label="Name" id="rg-admin-campaign-name" :value="$campaignDraft['name'] ?? ''" :error="$errors->first('name') ?: null" wire:model="campaignDraft.name" />
        <label class="rg-admin-field">
            <span class="rg-admin-label">Game</span>
            <select class="rg-admin-traffic__select" wire:model="campaignDraft.game_id">
                <option value="">No game</option>
                @foreach ($campaign['games'] as $id => $title)
                    <option value="{{ $id }}">{{ $title }}</option>
                @endforeach
            </select>
        </label>
        <x-admin.ui.text-field label="Target price of an account ($)" id="rg-admin-campaign-cpa" :value="$campaignDraft['target_cpa'] ?? ''" hint="Empty: the source’s." :error="$errors->first('target_cpa') ?: null" wire:model="campaignDraft.target_cpa" />
        <x-admin.ui.text-field label="Daily budget ($)" id="rg-admin-campaign-budget" :value="$campaignDraft['daily_budget'] ?? ''" wire:model="campaignDraft.daily_budget" />
        <label class="rg-admin-field">
            <span class="rg-admin-label">Spend</span>
            <select class="rg-admin-traffic__select" wire:model.live="campaignDraft.spend_mode">
                <option value="">As the source counts it</option>
                <option value="price">At this campaign’s price</option>
            </select>
        </label>
        @if (($campaignDraft['spend_mode'] ?? '') === 'price')
            <div class="rg-admin-traffic__row">
                <select class="rg-admin-traffic__select" wire:model="campaignDraft.bid_model" aria-label="Price per">
                    <option value="click">a click</option>
                    <option value="cpm">a thousand</option>
                </select>
                <x-admin.ui.text-field label="Mobile" id="rg-admin-campaign-mobile" :value="$campaignDraft['bid_mobile'] ?? ''" wire:model="campaignDraft.bid_mobile" />
                <x-admin.ui.text-field label="Desktop" id="rg-admin-campaign-desktop" :value="$campaignDraft['bid_desktop'] ?? ''" wire:model="campaignDraft.bid_desktop" />
                <x-admin.ui.text-field label="From" id="rg-admin-campaign-from" type="date" :value="$campaignDraft['price_from'] ?? ''" wire:model="campaignDraft.price_from" />
            </div>
        @endif
    </div>
    @if ($campaign['stages'] !== [])
        <div class="rg-admin-traffic__section">
            <span class="rg-admin-label">Stages</span>
            @foreach ($campaign['stages'] as $stage)
                <p class="rg-admin-traffic__note">{{ ucfirst($stage['stage']) }} from {{ $stage['started_at']->format('M j') }}: {{ number_format($stage['figures']->visits) }} visits · {{ $stage['figures']->accounts }} accounts · {{ $money($stage['figures']->spend) }}</p>
            @endforeach
        </div>
    @endif
    <div class="rg-admin-traffic__section">
        <span class="rg-admin-label">History</span>
        @forelse ($campaign['history'] as $event)
            <p class="rg-admin-traffic__note">{{ $event->occurred_at->format('M j, H:i') }} · {{ $event->summary }}</p>
        @empty
            <p class="rg-admin-traffic__note">Nothing yet.</p>
        @endforelse
    </div>
    </div>
    <x-slot:footer>
        @if ($model->status->value === 'archived')
            <x-admin.ui.button wire:click="archiveCampaign(true)">Restore</x-admin.ui.button>
        @else
            <x-admin.ui.button wire:click="archiveCampaign">Archive</x-admin.ui.button>
        @endif
        <x-admin.ui.button variant="primary" wire:click="saveCampaign">Save</x-admin.ui.button>
    </x-slot:footer>
</x-admin.ui.drawer>
