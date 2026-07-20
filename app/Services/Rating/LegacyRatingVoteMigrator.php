<?php

namespace App\Services\Rating;

use App\Contracts\Persistence\LowLevelDatabaseBoundary;
use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LegacyRatingVoteMigrator implements LowLevelDatabaseBoundary
{
    /**
     * @return array{
     *     origin_migrated: int,
     *     category_migrated: int,
     *     existing: int,
     *     unmapped: int
     * }
     */
    public function migrate(bool $dryRun = false): array
    {
        DB::beginTransaction();

        try {
            $options = $this->ensureConfiguration();
            $result = [
                'origin_migrated' => 0,
                'category_migrated' => 0,
                'existing' => 0,
                'unmapped' => 0,
            ];

            $this->migrateTable(
                table: 'origin_votes',
                valueColumn: 'origin',
                group: $options['source_group'],
                optionMap: [
                    'homemade' => $options['source_a'],
                    'restaurant' => $options['source_b'],
                ],
                migratedKey: 'origin_migrated',
                result: $result,
            );

            $this->migrateTable(
                table: 'cuisine_votes',
                valueColumn: 'cuisine',
                group: $options['category_group'],
                optionMap: [
                    'italian' => $options['category_a'],
                    'asian' => $options['category_b'],
                    'american' => $options['category_c'],
                    'mexican' => $options['category_d'],
                    'other' => $options['category_other'],
                ],
                migratedKey: 'category_migrated',
                result: $result,
            );

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $result;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * @return array{
     *     source_group: RatingGroup,
     *     source_a: RatingOption,
     *     source_b: RatingOption,
     *     category_group: RatingGroup,
     *     category_a: RatingOption,
     *     category_b: RatingOption,
     *     category_c: RatingOption,
     *     category_d: RatingOption,
     *     category_other: RatingOption
     * }
     */
    private function ensureConfiguration(): array
    {
        $source = $this->group('source', 'Source', 10);
        $category = $this->group('category', 'Category', 20);
        $this->ensureOptionCapacity($source, ['source_a', 'source_b']);
        $this->ensureOptionCapacity($category, [
            'category_a',
            'category_b',
            'category_c',
            'category_d',
            'category_other',
        ]);

        return [
            'source_group' => $source,
            'source_a' => $this->option($source, 'source_a', 'Source A', 10),
            'source_b' => $this->option($source, 'source_b', 'Source B', 20),
            'category_group' => $category,
            'category_a' => $this->option($category, 'category_a', 'Category A', 10),
            'category_b' => $this->option($category, 'category_b', 'Category B', 20),
            'category_c' => $this->option($category, 'category_c', 'Category C', 30),
            'category_d' => $this->option($category, 'category_d', 'Category D', 40),
            'category_other' => $this->option($category, 'category_other', 'Other', 50),
        ];
    }

    private function group(string $key, string $label, int $sortOrder): RatingGroup
    {
        return RatingGroup::query()->firstOrCreate(
            ['key' => $key],
            [
                'label' => $label,
                'description' => null,
                'min_options' => 2,
                'max_options' => 10,
                'is_active' => true,
                'sort_order' => $sortOrder,
            ],
        );
    }

    private function option(
        RatingGroup $group,
        string $key,
        string $label,
        int $sortOrder,
    ): RatingOption {
        return $group->options()->firstOrCreate(
            ['key' => $key],
            [
                'label' => $label,
                'description' => null,
                'is_active' => true,
                'sort_order' => $sortOrder,
                'archived_at' => null,
            ],
        );
    }

    /**
     * @param  list<string>  $requiredKeys
     */
    private function ensureOptionCapacity(RatingGroup $group, array $requiredKeys): void
    {
        $existingKeys = $group->options()
            ->whereIn('key', $requiredKeys)
            ->pluck('key');
        $missingCount = count($requiredKeys) - $existingKeys->count();
        $activeCount = $group->options()->active()->count();

        if ($activeCount + $missingCount > $group->max_options) {
            throw new InvalidRatingGroupConfigurationException(
                "Rating group [{$group->key}] cannot add {$missingCount} legacy mapping options; "
                ."its maximum is {$group->max_options}.",
            );
        }
    }

    /**
     * @param  array<string, RatingOption>  $optionMap
     * @param  array{origin_migrated: int, category_migrated: int, existing: int, unmapped: int}  $result
     */
    private function migrateTable(
        string $table,
        string $valueColumn,
        RatingGroup $group,
        array $optionMap,
        string $migratedKey,
        array &$result,
    ): void {
        DB::table($table)
            ->orderBy('id')
            ->chunkById(100, function ($votes) use (
                $valueColumn,
                $group,
                $optionMap,
                $migratedKey,
                &$result,
            ): void {
                foreach ($votes as $legacyVote) {
                    $option = $optionMap[$legacyVote->{$valueColumn}] ?? null;

                    if ($option === null) {
                        $result['unmapped']++;

                        continue;
                    }

                    $created = RatingVote::query()->insertOrIgnore([
                        'user_id' => $legacyVote->user_id,
                        'post_id' => $legacyVote->post_id,
                        'rating_group_id' => $group->id,
                        'rating_option_id' => $option->id,
                        'created_at' => $legacyVote->created_at,
                        'updated_at' => $legacyVote->updated_at,
                    ]);

                    if ($created === 1) {
                        $result[$migratedKey]++;
                    } else {
                        $result['existing']++;
                    }
                }
            });
    }
}
