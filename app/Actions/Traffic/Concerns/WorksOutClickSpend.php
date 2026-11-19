<?php

namespace App\Actions\Traffic\Concerns;

use App\Enums\TrafficDevice;
use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendMode;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignPrice;
use App\Models\TrafficSource;
use App\Support\Traffic\Spend\ClickSpend;
use Carbon\CarbonImmutable;

/**
 * What a click is taken to have cost as it came in (ClickSpend), by how its
 * campaign — else its source — counts spend; a campaign priced by the day
 * uses the price in force when the click came. The click writer prices a
 * click with it, and spend allocation prices a day's clicks with it again
 * when the figures that replaced their prices are gone.
 */
trait WorksOutClickSpend
{
    /** @return array{spend: string, spend_basis: TrafficSpendBasis, spend_estimated: bool} */
    private function clickSpend(TrafficSource $source, ?TrafficCampaign $campaign, ?string $cost, TrafficDevice $device, CarbonImmutable $at): array
    {
        $mode = $campaign->spend_mode ?? $source->spend_mode;
        $price = $mode === TrafficSpendMode::Price && $campaign !== null
            ? TrafficCampaignPrice::query()->where('campaign_id', $campaign->id)->where('device', $device)->where('effective_from', '<=', $at)
                ->orderByDesc('effective_from')->orderByDesc('id')->first()
            : null;
        $spend = ClickSpend::of(
            $mode,
            $cost,
            $device === TrafficDevice::Mobile ? $source->rate_mobile : $source->rate_desktop,
            $source->rate_unit,
            $price === null ? null : ['price' => (string) $price->price, 'model' => $price->bid_model],
            $source->spend_estimate,
        );

        return ['spend' => $spend['spend'], 'spend_basis' => $spend['basis'], 'spend_estimated' => $spend['estimated']];
    }
}
