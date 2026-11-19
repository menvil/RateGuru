<?php

namespace App\Filament\Concerns;

use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * A report page's period, as the period picker sets it and the address
 * keeps it: one of the page's presets (`period`, from its PERIODS), or a
 * range of whole days chosen in its calendar (`from`, `to`). Whatever the
 * address or an update sends is read through ReportPeriod and written back
 * as read, so a range that cannot be read becomes the default preset, never
 * an error. The page says how many days back its data is kept, and what else
 * starts over when the period changes.
 */
trait ChoosesReportPeriod
{
    #[Url(except: '30d')]
    public mixed $period = '30d';

    /** A range's first day, Y-m-d; none for a preset. */
    #[Url(except: '')]
    public mixed $from = '';

    /** A range's last day, Y-m-d; none for a preset. */
    #[Url(except: '')]
    public mixed $to = '';

    /** How many days back, today included, the page's data is kept: no range may start earlier. */
    abstract protected function keptDays(): int;

    /** What else starts over once the period has changed. */
    protected function periodChanged(): void {}

    public function choosePeriod(mixed $preset): void
    {
        $this->period = $preset;
        $this->from = '';
        $this->to = '';
        $this->settlePeriod();
        $this->periodChanged();
    }

    public function applyRange(mixed $from, mixed $to): void
    {
        $this->period = '30d';
        $this->from = $from;
        $this->to = $to;
        $this->settlePeriod();
        $this->periodChanged();
    }

    protected function reportPeriod(): ReportPeriod
    {
        return ReportPeriod::read(static::PERIODS, '30d', $this->period, $this->from, $this->to, CarbonImmutable::now('UTC'), $this->keptDays());
    }

    /** Puts the period back in the address as it was read: the preset, or the range. */
    protected function settlePeriod(): void
    {
        $period = $this->reportPeriod();
        $this->period = $period->isCustom() ? '30d' : $period->preset;
        $this->from = $period->fromDay ?? '';
        $this->to = $period->toDay ?? '';
    }

    /**
     * What the period picker shows and offers: the presets, which is chosen,
     * the trigger's name and days, the range its calendar starts from — the
     * period's own days — and the days a range may take.
     *
     * @param  string|null  $dates  the period's days as the trigger names them; none for all time
     * @param  array{0: string, 1: string}  $days  the period's first and last day, Y-m-d, drawn as the range's calendar's range; empty for a period without days of its own
     * @return array<string, mixed>
     */
    protected function periodPicker(?string $dates, array $days): array
    {
        $period = $this->reportPeriod();
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return [
            'presets' => static::PERIODS,
            'value' => $period->preset,
            'label' => $period->isCustom() ? 'Custom range' : static::PERIODS[$period->preset],
            'dates' => $dates,
            'from' => $period->fromDay ?? $days[0],
            'to' => $period->toDay ?? $days[1],
            'min' => $today->subDays($this->keptDays() - 1)->toDateString(),
            'max' => $today->toDateString(),
        ];
    }
}
