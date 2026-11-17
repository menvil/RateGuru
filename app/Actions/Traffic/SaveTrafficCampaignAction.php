<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficActor;
use App\Enums\TrafficCampaignEventKind;
use App\Enums\TrafficCostUnit;
use App\Enums\TrafficDevice;
use App\Enums\TrafficSpendMode;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\Game;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignPrice;
use App\Models\User;
use App\Support\Geo\Countries;
use App\Support\Traffic\Money\TrafficMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves a campaign as its editor holds it: its name and game, how its
 * targeting was set up in the network, its daily budget and target price of
 * an account, and how its spend is counted — as the source counts it, or at
 * a price per click or per thousand that takes effect from a day, so the
 * visits before that day keep the price they had. Each change is a line of
 * its history.
 */
final class SaveTrafficCampaignAction
{
    use LocksTrafficRecords;

    public function __construct(private readonly RecordTrafficCampaignEventAction $history) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws CannotManageTrafficException|ValidationException
     */
    public function handle(User $admin, TrafficCampaign $campaign, array $input, int $lockVersion): TrafficCampaign
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:1000000'];
        Validator::make($input, [
            'name' => ['nullable', 'string', 'max:120'],
            'game_id' => ['nullable', 'integer', Rule::exists('games', 'id')],
            'format' => ['nullable', 'string', 'max:60'],
            'countries' => ['nullable', 'array', 'max:250'],
            'countries.*' => ['string', Rule::in(array_keys(Countries::all()))],
            'devices' => ['nullable', 'array'],
            'devices.*' => [Rule::enum(TrafficDevice::class)],
            'daily_budget' => $money,
            'target_cpa' => [...$money, 'max:1000'],
            'whitelist_name' => ['nullable', 'string', 'max:120'],
            'spend_mode' => ['nullable', Rule::in([TrafficSpendMode::Price->value])],
            'bid_model' => ['nullable', Rule::enum(TrafficCostUnit::class), Rule::requiredIf(($input['spend_mode'] ?? null) === TrafficSpendMode::Price->value)],
            'bid_mobile' => [...$money, 'max:100'],
            'bid_desktop' => [...$money, 'max:100'],
            'price_from' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['game_id' => 'game'])->validate();

        return DB::transaction(function () use ($admin, $campaign, $input, $lockVersion): TrafficCampaign {
            $locked = $this->lockAdministrator($admin);
            $record = $this->lockCampaign((int) $campaign->id, $lockVersion);
            $amount = fn (string $key, int $scale): ?string => ($input[$key] ?? null) === null || $input[$key] === '' ? null : TrafficMoney::stored((string) $input[$key], $scale);
            $before = $record->only(['name', 'game_id', 'daily_budget', 'target_cpa', 'spend_mode', 'bid_model', 'bid_mobile', 'bid_desktop', 'countries', 'devices', 'format']);

            $record->forceFill([
                'name' => ($input['name'] ?? '') !== '' ? trim((string) $input['name']) : null,
                'game_id' => isset($input['game_id']) ? (int) $input['game_id'] : null,
                // Targeting the network reported stays as it is unless the panel names it.
                'format' => array_key_exists('format', $input) ? (($input['format'] ?? '') !== '' ? trim((string) $input['format']) : null) : $record->format,
                'countries' => array_key_exists('countries', $input) ? (array_values(array_unique((array) ($input['countries'] ?? []))) ?: null) : $record->countries,
                'devices' => array_key_exists('devices', $input) ? (array_values(array_unique((array) ($input['devices'] ?? []))) ?: null) : $record->devices,
                'daily_budget' => $amount('daily_budget', 2),
                'target_cpa' => $amount('target_cpa', 4),
                'whitelist_name' => array_key_exists('whitelist_name', $input) ? (($input['whitelist_name'] ?? '') !== '' ? trim((string) $input['whitelist_name']) : null) : $record->whitelist_name,
                'spend_mode' => ($input['spend_mode'] ?? null) === null ? null : TrafficSpendMode::from((string) $input['spend_mode']),
                'bid_model' => $input['bid_model'] ?? null,
                'bid_mobile' => $amount('bid_mobile', 6),
                'bid_desktop' => $amount('bid_desktop', 6),
                'set_up_at' => $record->set_up_at ?? now(),
                'lock_version' => $record->lock_version + 1,
            ])->save();

            $this->recordChanges($record, $before, (int) $locked->id);

            if ($record->spend_mode === TrafficSpendMode::Price) {
                $this->priceFrom($record, CarbonImmutable::parse((string) ($input['price_from'] ?? now()->toDateString()))->startOfDay(), (int) $locked->id);
            }

            return $record;
        });
    }

    /** The campaign's prices, per device, from a day on — replacing any set for that same day. */
    private function priceFrom(TrafficCampaign $campaign, CarbonImmutable $from, int $userId): void
    {
        foreach ([[TrafficDevice::Mobile, $campaign->bid_mobile], [TrafficDevice::Desktop, $campaign->bid_desktop]] as [$device, $price]) {
            if ($price === null) {
                continue;
            }

            $current = TrafficCampaignPrice::query()
                ->where('campaign_id', $campaign->id)
                ->where('device', $device)
                ->where('effective_from', '<=', $from)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            if ($current !== null && $current->bid_model === $campaign->bid_model && TrafficMoney::of($current->price)->isEqualTo(TrafficMoney::of($price))) {
                continue;
            }

            TrafficCampaignPrice::query()->where('campaign_id', $campaign->id)->where('device', $device)->where('effective_from', $from)->delete();
            TrafficCampaignPrice::query()->create([
                'campaign_id' => $campaign->id,
                'device' => $device,
                'bid_model' => (string) $campaign->bid_model,
                'price' => $price,
                'effective_from' => $from,
                'actor' => TrafficActor::User,
                'actor_user_id' => $userId,
            ]);

            $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Bid, ucfirst($device->value).' price '.TrafficMoney::format($price, 4).($campaign->bid_model === TrafficCostUnit::Cpm->value ? ' CPM' : ' a click').' from '.$from->toDateString(), TrafficActor::User, $userId);
        }
    }

    /** @param  array<string, mixed>  $before */
    private function recordChanges(TrafficCampaign $campaign, array $before, int $userId): void
    {
        if ($before['name'] !== $campaign->name) {
            $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Targeting, 'Renamed to “'.($campaign->name ?? 'campaign '.$campaign->external_id).'”', TrafficActor::User, $userId);
        }

        if ($before['game_id'] !== $campaign->game_id) {
            $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Targeting, $campaign->game_id === null ? 'No game' : 'Leads to '.Game::query()->whereKey($campaign->game_id)->value('title'), TrafficActor::User, $userId);
        }

        if ($before['countries'] !== $campaign->countries || $before['devices'] !== $campaign->devices || $before['format'] !== $campaign->format) {
            $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Targeting, 'Targeting set: '.implode(' · ', array_filter([
                $campaign->format,
                $campaign->countries === null ? null : implode(', ', $campaign->countries),
                $campaign->devices === null ? null : implode(', ', $campaign->devices),
            ])), TrafficActor::User, $userId);
        }

        if ((string) $before['daily_budget'] !== (string) $campaign->daily_budget) {
            $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Budget, $campaign->daily_budget === null ? 'No daily budget' : 'Daily budget '.TrafficMoney::format($campaign->daily_budget), TrafficActor::User, $userId);
        }

        if ((string) $before['target_cpa'] !== (string) $campaign->target_cpa) {
            $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Target, $campaign->target_cpa === null ? 'Target price of an account: the source’s' : 'Target price of an account '.TrafficMoney::format($campaign->target_cpa), TrafficActor::User, $userId);
        }

        if ($before['spend_mode'] !== $campaign->spend_mode) {
            $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Spend, $campaign->spend_mode === null ? 'Spend counted as the source counts it' : 'Spend counted at the campaign’s price', TrafficActor::User, $userId);
        }
    }
}
