<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficChannel;
use App\Enums\TrafficCostUnit;
use App\Enums\TrafficEvent;
use App\Enums\TrafficFixedPeriod;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendMode;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Support\Traffic\Links\LinkParams;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Postbacks\PostbackTemplate;
use App\Support\Traffic\Presets\PresetSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves a traffic source as its editor holds it — every tab of it at once:
 * the link and its parameters, the postback rules, spend and blocking — or
 * creates one from the defaults its preset gave (SourceDefaults). A source
 * is never deleted; its key and its reporting time zone stay as they are
 * once a visit has come through it, since visits and spend are filed by
 * them. Pausing or ending it cancels the postbacks still waiting to be sent.
 *
 * Errors come back keyed by the editor's fields (`link_key`,
 * `link_params.2.param`, `rules.0.url_template`).
 */
final class SaveTrafficSourceAction
{
    use LocksTrafficRecords;

    public function __construct(private readonly MaterializeFixedSpendAction $fixed) {}

    /**
     * @param  array<string, mixed>  $source  the source's fields
     * @param  list<array<string, mixed>>  $rules  its postback rules, in order
     *
     * @throws CannotManageTrafficException|ValidationException
     */
    public function handle(User $admin, ?int $sourceId, array $source, array $rules, ?int $lockVersion = null): TrafficSource
    {
        return DB::transaction(function () use ($admin, $sourceId, $source, $rules, $lockVersion): TrafficSource {
            $locked = $this->lockAdministrator($admin);
            $existing = $sourceId === null ? null : $this->lockSource($sourceId, $lockVersion);
            $type = $existing->type ?? TrafficSourceType::tryFrom((string) ($source['type'] ?? ''));

            if ($type === null) {
                throw ValidationException::withMessages(['type' => 'Choose a network, or a banner or link.']);
            }

            $this->validate($type, $existing, $source, $rules);
            $hasVisits = $existing !== null && TrafficVisit::query()->where('source_id', $existing->id)->exists();

            if ((bool) ($source['block_auto'] ?? false) && ! $this->canBlockByItself($existing, TrafficBlockLevel::tryFrom((string) ($source['block_level'] ?? '')))) {
                throw ValidationException::withMessages(['block_auto' => 'Blocking by itself needs the network’s API connected, checked on a real account, and able to block this level.']);
            }

            if ($hasVisits && $existing->link_key !== $source['link_key']) {
                throw ValidationException::withMessages(['link_key' => 'Visits have come through this key, so it can’t change: links already in campaigns would stop counting.']);
            }

            if ($hasVisits && $existing->reporting_timezone !== $source['reporting_timezone']) {
                throw ValidationException::withMessages(['reporting_timezone' => 'Visits are already filed by this time zone’s days, so it can’t change.']);
            }

            $record = $existing ?? new TrafficSource;
            $status = TrafficSourceStatus::from((string) $source['status']);
            $wasActive = $existing === null || $existing->status === TrafficSourceStatus::Active;

            $record->forceFill([
                ...($existing === null ? [
                    'type' => $type,
                    'preset_key' => $source['preset_key'] ?? null,
                    'connector' => $source['connector'] ?? null,
                    'created_by_user_id' => $locked->id,
                    'lock_version' => 1,
                ] : ['lock_version' => $existing->lock_version + 1]),
                ...$this->attributes($type, $source),
                'status' => $status,
                'updated_by_user_id' => $locked->id,
            ])->save();

            if ($type === TrafficSourceType::Network) {
                $this->saveRules($record, $rules);
            }

            if ($wasActive && $status !== TrafficSourceStatus::Active) {
                TrafficPostback::query()
                    ->where('source_id', $record->id)
                    ->where('status', TrafficPostbackStatus::Pending)
                    ->update(['status' => TrafficPostbackStatus::Cancelled, 'finished_at' => now()]);
            }

            if ($type === TrafficSourceType::Placement) {
                $this->fixed->handle($record->refresh());
            }

            return $record->refresh();
        });
    }

    private function canBlockByItself(?TrafficSource $source, ?TrafficBlockLevel $level): bool
    {
        return $source !== null && $source->connector !== null && $level !== null
            && app(ConnectorRegistry::class)->verified($source->connector)
            && app(BlockTrafficRefsAction::class)->canBlockViaApi($source, $level);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<array<string, mixed>>  $rules
     */
    private function validate(TrafficSourceType $type, ?TrafficSource $existing, array $source, array $rules): void
    {
        $network = $type === TrafficSourceType::Network;
        $spendModes = $network
            ? [TrafficSpendMode::ClickCost, TrafficSpendMode::Rate, TrafficSpendMode::Manual, TrafficSpendMode::None, ...(($source['connector'] ?? $existing?->connector) !== null ? [TrafficSpendMode::Api] : [])]
            : [TrafficSpendMode::Fixed, TrafficSpendMode::Manual, TrafficSpendMode::None];
        $money = ['nullable', 'numeric', 'min:0', 'max:1000000'];

        $validator = Validator::make(['source' => $source, 'rules' => $rules], [
            'source.name' => ['required', 'string', 'max:80'],
            'source.link_key' => ['required', 'string', 'regex:/^[a-z0-9_-]{2,40}$/', Rule::notIn(array_map(fn (TrafficChannel $channel): string => $channel->value, TrafficChannel::cases())),
                Rule::unique('traffic_sources', 'link_key')->ignore($existing?->id)],
            'source.status' => ['required', Rule::enum(TrafficSourceStatus::class)],
            'source.attribution_days' => ['required', 'integer', Rule::in(PresetSchema::ATTRIBUTION_DAYS)],
            'source.click_id_ttl_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'source.target_cpa' => [...$money, 'max:1000'],
            'source.reporting_timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'source.link_params' => ['array'],
            'source.response_rules' => ['nullable', 'array'],
            'source.response_rules.rules' => ['array', 'max:20'],
            'source.response_rules.rules.*.outcome' => ['required', Rule::in(['ok', 'duplicate', 'rejected'])],
            'source.response_rules.rules.*.match' => ['required', Rule::in(['equals', 'contains', 'header'])],
            'source.response_rules.rules.*.text' => ['required', 'string', 'max:200'],
            'source.response_rules.otherwise' => ['required_with:source.response_rules', Rule::in(['ok', 'retry'])],
            'source.spend_mode' => ['required', Rule::in(array_map(fn (TrafficSpendMode $mode): string => $mode->value, $spendModes))],
            'source.spend_estimate' => ['nullable', Rule::in([TrafficSpendMode::ClickCost->value, TrafficSpendMode::Rate->value])],
            'source.cost_unit' => ['required', Rule::enum(TrafficCostUnit::class)],
            'source.rate_unit' => ['required', Rule::enum(TrafficCostUnit::class)],
            'source.rate_mobile' => [...$money, 'max:100'],
            'source.rate_desktop' => [...$money, 'max:100'],
            'source.fixed_amount' => [...$money, Rule::requiredIf(($source['spend_mode'] ?? null) === TrafficSpendMode::Fixed->value)],
            'source.fixed_period' => ['nullable', Rule::enum(TrafficFixedPeriod::class), Rule::requiredIf(($source['spend_mode'] ?? null) === TrafficSpendMode::Fixed->value)],
            'source.fixed_from' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf(($source['spend_mode'] ?? null) === TrafficSpendMode::Fixed->value)],
            'source.fixed_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:source.fixed_from'],
            'source.block_level' => ['required', Rule::enum(TrafficBlockLevel::class)],
            'source.block_auto' => ['boolean'],
            'source.block_daily_cap' => ['required', 'integer', 'min:1', 'max:100'],
            'source.place_site' => ['nullable', 'string', 'max:120'],
            'source.place_position' => ['nullable', 'string', 'max:120'],
            'source.creatives' => ['nullable', 'array', 'max:20'],
            'source.creatives.*' => ['string', 'regex:/^[a-z0-9_-]{1,40}$/', 'distinct'],
            'rules' => ['array', 'max:'.(int) config('traffic.rules_per_source', 20)],
            'rules.*.id' => ['nullable', 'integer'],
            'rules.*.event' => ['required', Rule::in(array_map(fn (TrafficEvent $event): string => $event->value, TrafficEvent::postbackEvents()))],
            'rules.*.goal_code' => ['nullable', 'string', 'max:60'],
            'rules.*.url_template' => ['present', 'nullable', 'string', 'max:'.PostbackTemplate::MAX_LENGTH],
            'rules.*.enabled' => ['boolean'],
        ], [], ['source.link_key' => 'key', 'source.name' => 'name']);

        $validator->after(function ($validator) use ($network, $source, $rules): void {
            if ($network) {
                foreach (LinkParams::violations(is_array($source['link_params'] ?? null) ? $source['link_params'] : [], (array) config('traffic.reserved_params', [])) as $problem) {
                    $validator->errors()->add($problem['index'] >= 0 ? "source.link_params.{$problem['index']}.{$problem['field']}" : 'source.link_params', $problem['message']);
                }
            } elseif (($source['link_params'] ?? []) !== []) {
                $validator->errors()->add('source.link_params', 'A banner or link carries no network parameters.');
            }

            if (! $network && $rules !== []) {
                $validator->errors()->add('rules', 'A banner or link sends no postbacks.');
            }

            if (($source['spend_mode'] ?? null) === TrafficSpendMode::Rate->value && ($source['rate_mobile'] ?? null) === null && ($source['rate_desktop'] ?? null) === null) {
                $validator->errors()->add('source.rate_mobile', 'Enter a rate for mobile, desktop or both.');
            }

            $seen = [];

            foreach ($rules as $index => $rule) {
                $template = trim((string) ($rule['url_template'] ?? ''));

                if ((bool) ($rule['enabled'] ?? false)) {
                    foreach (PostbackTemplate::violations($template) as $problem) {
                        $validator->errors()->add("rules.{$index}.url_template", $problem);
                    }
                }

                $key = ($rule['event'] ?? '').'|'.($rule['goal_code'] ?? '').'|'.$template;

                if (in_array($key, $seen, true)) {
                    $validator->errors()->add("rules.{$index}.url_template", 'Another rule already sends this address for this event.');
                }

                $seen[] = $key;
            }
        });

        try {
            $validator->validate();
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $field => $errors) {
                $messages[preg_replace('/^source\./', '', $field)] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function attributes(TrafficSourceType $type, array $source): array
    {
        $money = fn (string $key, int $scale = 4): ?string => ($source[$key] ?? null) === null || $source[$key] === '' ? null : TrafficMoney::stored((string) $source[$key], $scale);
        $network = $type === TrafficSourceType::Network;

        return [
            'name' => trim((string) $source['name']),
            'link_key' => (string) $source['link_key'],
            'attribution_days' => (int) $source['attribution_days'],
            'click_id_ttl_hours' => isset($source['click_id_ttl_hours']) ? (int) $source['click_id_ttl_hours'] : null,
            'target_cpa' => $money('target_cpa'),
            'reporting_timezone' => (string) $source['reporting_timezone'],
            'link_params' => $network ? array_values(array_map(fn (array $param): array => [
                'kind' => (string) $param['kind'],
                'label' => trim((string) $param['label']),
                'param' => (string) $param['param'],
                'token' => trim((string) ($param['token'] ?? '')),
                'note' => trim((string) ($param['note'] ?? '')),
            ], (array) ($source['link_params'] ?? []))) : [],
            'response_rules' => $network && is_array($source['response_rules'] ?? null) ? [
                'rules' => array_values(array_map(fn (array $rule): array => ['outcome' => (string) $rule['outcome'], 'match' => (string) $rule['match'], 'text' => trim((string) $rule['text'])], (array) ($source['response_rules']['rules'] ?? []))),
                'otherwise' => (string) ($source['response_rules']['otherwise'] ?? 'ok'),
            ] : null,
            'spend_mode' => TrafficSpendMode::from((string) $source['spend_mode']),
            'spend_estimate' => $source['spend_estimate'] ?? null,
            'cost_unit' => TrafficCostUnit::from((string) $source['cost_unit']),
            'rate_unit' => TrafficCostUnit::from((string) $source['rate_unit']),
            'rate_mobile' => $money('rate_mobile', 6),
            'rate_desktop' => $money('rate_desktop', 6),
            'fixed_amount' => $network ? null : $money('fixed_amount', 2),
            'fixed_period' => $network || ($source['fixed_period'] ?? null) === null ? null : TrafficFixedPeriod::from((string) $source['fixed_period']),
            // A date field cleared in the browser comes back empty: no date, not today.
            'fixed_from' => $network || trim((string) ($source['fixed_from'] ?? '')) === '' ? null : $source['fixed_from'],
            'fixed_to' => $network || trim((string) ($source['fixed_to'] ?? '')) === '' ? null : $source['fixed_to'],
            'block_level' => TrafficBlockLevel::from((string) $source['block_level']),
            'block_auto' => (bool) ($source['block_auto'] ?? false),
            'block_daily_cap' => (int) $source['block_daily_cap'],
            'place_site' => $network ? null : (($source['place_site'] ?? '') !== '' ? trim((string) $source['place_site']) : null),
            'place_position' => $network ? null : (($source['place_position'] ?? '') !== '' ? trim((string) $source['place_position']) : null),
            'creatives' => $network ? null : array_values((array) ($source['creatives'] ?? [])),
        ];
    }

    /**
     * The rules as the editor lists them: kept, changed, added, and the ones
     * it no longer lists removed. A postback already sent for a removed rule
     * keeps its record, without the rule.
     *
     * @param  list<array<string, mixed>>  $rules
     */
    private function saveRules(TrafficSource $source, array $rules): void
    {
        $kept = [];

        foreach ($rules as $position => $rule) {
            $id = isset($rule['id']) ? (int) $rule['id'] : null;
            $record = $id === null ? new TrafficPostbackRule : TrafficPostbackRule::query()->where('source_id', $source->id)->whereKey($id)->first();

            if ($record === null) {
                throw CannotManageTrafficException::becauseItChanged();
            }

            $record->forceFill([
                'source_id' => $source->id,
                'event' => TrafficEvent::from((string) $rule['event']),
                'goal_code' => ($rule['goal_code'] ?? '') !== '' ? trim((string) $rule['goal_code']) : null,
                'url_template' => trim((string) ($rule['url_template'] ?? '')),
                'enabled' => (bool) ($rule['enabled'] ?? false),
                'position' => $position,
            ])->save();

            $kept[] = $record->id;
        }

        TrafficPostbackRule::query()->where('source_id', $source->id)->whereNotIn('id', $kept)->delete();
    }
}
