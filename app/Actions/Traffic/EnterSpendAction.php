<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficSpendOrigin;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficSpendImport;
use App\Models\User;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Spend\SpendCsv;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Spend the administrator gives by hand: a day's total typed in — one figure
 * per source and day, replaced by the next, removed by an empty one — or a
 * file of the network's figures (SpendCsv), which replaces the file figures
 * of its days. A typed total counts before a file, a file before the API.
 * Rows of campaigns RateGuru never saw a click of are left out and counted:
 * a network account can hold campaigns sent elsewhere.
 */
final class EnterSpendAction
{
    use LocksTrafficRecords;

    public function __construct(private readonly ReplaceSpendSliceAction $slices) {}

    /** @throws CannotManageTrafficException|ValidationException */
    public function day(User $admin, TrafficSource $source, string $day, mixed $amount): void
    {
        $today = CarbonImmutable::now($source->reporting_timezone)->toDateString();
        Validator::make(['day' => $day, 'amount' => $amount], [
            'day' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ], ['day.before_or_equal' => 'That day hasn’t come yet in the network’s time zone.'])->validate();

        DB::transaction(function () use ($admin, $source, $day, $amount): void {
            $locked = $this->lockAdministrator($admin);
            $this->lockSource((int) $source->id);
            $this->slices->handle((int) $source->id, $day, TrafficSpendOrigin::Manual, $amount === null || $amount === ''
                ? []
                : [['campaign_id' => null, 'site_ref' => '', 'zone_ref' => '', 'amount' => (string) $amount]], userId: (int) $locked->id);
        });
    }

    /** @throws CannotManageTrafficException|ValidationException */
    public function file(User $admin, TrafficSource $source, string $fileName, string $contents): TrafficSpendImport
    {
        $parsed = SpendCsv::parse($contents, CarbonImmutable::now($source->reporting_timezone)->toDateString());

        return DB::transaction(function () use ($admin, $source, $fileName, $parsed): TrafficSpendImport {
            $locked = $this->lockAdministrator($admin);
            $this->lockSource((int) $source->id);

            if ($parsed['errors'] !== []) {
                throw ValidationException::withMessages(['file' => $parsed['errors']]);
            }

            $campaigns = TrafficCampaign::query()->where('source_id', $source->id)->pluck('id', 'external_id');
            $import = TrafficSpendImport::query()->create([
                'source_id' => $source->id,
                'file_name' => mb_substr($fileName, 0, 190),
                'status' => 'importing',
                'uploaded_by_user_id' => $locked->id,
            ]);
            $byDay = [];
            $skipped = 0;
            $skippedAmount = '0';

            foreach ($parsed['rows'] as $row) {
                $campaignId = $row['campaign'] === '' ? null : ($campaigns[$row['campaign']] ?? false);

                if ($campaignId === false) {
                    $skipped++;
                    $skippedAmount = TrafficMoney::stored(TrafficMoney::of($skippedAmount)->plus(TrafficMoney::of($row['amount'])));

                    continue;
                }

                $byDay[$row['day']][] = ['campaign_id' => $campaignId, 'site_ref' => $row['site'], 'zone_ref' => $row['zone'], 'amount' => $row['amount'], 'clicks' => $row['clicks'], 'impressions' => $row['impressions']];
            }

            foreach ($byDay as $day => $rows) {
                $this->slices->handle((int) $source->id, (string) $day, TrafficSpendOrigin::Csv, $rows, (int) $import->id, (int) $locked->id);
            }

            $days = array_keys($byDay);
            sort($days);
            $import->forceFill([
                'status' => 'done',
                'fact_rows' => array_sum(array_map('count', $byDay)),
                'day_from' => $days[0] ?? null,
                'day_to' => $days === [] ? null : end($days),
                'total' => TrafficMoney::stored(array_reduce(array_merge([], ...array_values($byDay)), fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus(TrafficMoney::of($row['amount'])), BigDecimal::zero())),
                'skipped_rows' => $skipped,
                'skipped_amount' => $skippedAmount,
            ])->save();

            return $import;
        });
    }
}
