<?php

namespace App\Actions\Analytics;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficSegment;
use App\Models\TrafficSource;
use App\Models\User;
use App\Queries\Analytics\TrafficSegmentsQuery;
use App\Support\Analytics\SegmentRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saves a paid source's segment as its drawer holds it: a name and up to
 * SegmentRules::MOST_CONDITIONS conditions, each read by SegmentRules — a
 * campaign named by its id in the network only one of the source's own,
 * kept as that campaign's id. What is wrong is said by the field it is
 * about, and nothing is written. A new segment is made while the source
 * holds fewer than MOST_SEGMENTS; one edited is saved only at the version
 * its drawer was drawn from (lock_version), and never moved to another
 * source.
 */
final class SaveTrafficSegmentAction
{
    use LocksTrafficRecords;

    /** The most segments a source holds, those deleted left out: every one is read with each figure of its card. */
    public const int MOST_SEGMENTS = 20;

    public function __construct(private readonly TrafficSegmentsQuery $segments) {}

    /**
     * @param  array<string, mixed>  $input  `name`, and `conditions` — each a parameter, an operator and the values as typed
     * @param  int|null  $segmentId  the segment edited; none for a new one
     * @param  int|null  $lockVersion  the edited segment's version as its drawer was drawn from
     *
     * @throws CannotManageTrafficException|ValidationException
     */
    public function handle(User $admin, int $sourceId, ?int $segmentId, array $input, ?int $lockVersion = null): TrafficSegment
    {
        $input['name'] = is_string($input['name'] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $input['name'])) : null;

        Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'conditions' => ['required', 'array', 'min:1', 'max:'.SegmentRules::MOST_CONDITIONS],
        ], [
            'name.required' => 'Name the segment.',
            'conditions.required' => 'Add at least one condition.',
            'conditions.min' => 'Add at least one condition.',
            'conditions.max' => 'Up to '.SegmentRules::MOST_CONDITIONS.' conditions.',
        ])->validate();

        $source = TrafficSource::query()->find($sourceId);

        if ($source === null) {
            throw CannotManageTrafficException::becauseItIsGone();
        }

        ['rules' => $rules, 'errors' => $errors] = $this->segments->rules($sourceId, $source->name, array_values((array) $input['conditions']));

        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $error, int $index): array => ["conditions.{$index}.values" => $error])->all());
        }

        return DB::transaction(function () use ($admin, $source, $segmentId, $input, $rules, $lockVersion): TrafficSegment {
            $locked = $this->lockAdministrator($admin);

            if ($segmentId === null) {
                $this->lockSource((int) $source->id);

                if (TrafficSegment::query()->where('source_id', $source->id)->count() >= self::MOST_SEGMENTS) {
                    throw CannotManageTrafficException::because($source->name.' already has '.self::MOST_SEGMENTS.' segments, the most a source holds. Delete one first.');
                }

                return TrafficSegment::query()->forceCreate([
                    'source_id' => $source->id,
                    'name' => $input['name'],
                    'rules' => SegmentRules::store($rules),
                    'created_by_user_id' => $locked->id,
                    'lock_version' => 1,
                ]);
            }

            $segment = $this->lockSegment($segmentId, $lockVersion);

            if ($segment->source_id !== (int) $source->id) {
                throw CannotManageTrafficException::becauseItIsGone('This segment');
            }

            $segment->forceFill([
                'name' => $input['name'],
                'rules' => SegmentRules::store($rules),
                'updated_by_user_id' => $locked->id,
                'lock_version' => $segment->lock_version + 1,
            ])->save();

            return $segment;
        });
    }
}
