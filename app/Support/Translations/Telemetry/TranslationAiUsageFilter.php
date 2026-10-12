<?php

namespace App\Support\Translations\Telemetry;

use App\Models\TranslationAiProviderCall;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Which recorded provider calls a Translation AI figure covers: those that
 * completed in [from, until) — from included, until not, both in UTC — and,
 * optionally, only one provider, model, installed language or consumer.
 *
 * Half-open, so consecutive periods neither overlap nor leave a gap. Showing
 * the period in an administrator's time zone is the page's job; the bounds
 * here are instants, converted to UTC whatever zone they were given in.
 */
final readonly class TranslationAiUsageFilter
{
    public CarbonImmutable $from;

    public CarbonImmutable $until;

    public function __construct(
        DateTimeInterface $from,
        DateTimeInterface $until,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $targetLocale = null,
        public ?TranslationAiConsumer $consumer = null,
    ) {
        $this->from = CarbonImmutable::instance($from)->utc();
        $this->until = CarbonImmutable::instance($until)->utc();

        if (! $this->from->lessThan($this->until)) {
            throw new InvalidArgumentException('A Translation AI usage period must end after it starts.');
        }
    }

    /** The last $days days up to now. */
    public static function lastDays(int $days): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('A Translation AI usage period covers at least one day.');
        }

        $now = CarbonImmutable::now('UTC');

        return new self($now->subDays($days), $now);
    }

    /**
     * @param  Builder<TranslationAiProviderCall>  $query
     * @return Builder<TranslationAiProviderCall>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->where('completed_at', '>=', $this->from)
            ->where('completed_at', '<', $this->until)
            ->when($this->provider !== null, fn (Builder $query) => $query->where('provider', $this->provider))
            ->when($this->model !== null, fn (Builder $query) => $query->where('model', $this->model))
            ->when($this->targetLocale !== null, fn (Builder $query) => $query->where('target_locale', $this->targetLocale))
            ->when($this->consumer !== null, fn (Builder $query) => $query->where('consumer', $this->consumer?->value));
    }

    /** @return Builder<TranslationAiProviderCall> */
    public function query(): Builder
    {
        return $this->apply(TranslationAiProviderCall::query());
    }
}
