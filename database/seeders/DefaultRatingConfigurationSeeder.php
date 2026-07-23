<?php

namespace Database\Seeders;

use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Services\Rating\LegacyDefaultRatingConfigurationSynchronizer;
use Illuminate\Database\Seeder;

class DefaultRatingConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        if (ProjectSettings::query()->whereNotNull('preset_applied_at')->exists()) {
            return;
        }

        app(LegacyDefaultRatingConfigurationSynchronizer::class)->synchronize();

        foreach ($this->configuration() as $groupData) {
            $options = $groupData['options'];
            unset($groupData['options']);

            $group = RatingGroup::query()->updateOrCreate(
                ['key' => $groupData['key']],
                $groupData,
            );

            foreach ($options as $optionData) {
                $group->options()->updateOrCreate(
                    ['key' => $optionData['key']],
                    $optionData,
                );
            }
        }
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string|null,
     *     min_options: int,
     *     max_options: int,
     *     is_active: bool,
     *     sort_order: int,
     *     options: list<array{
     *         key: string,
     *         label: string,
     *         description: string|null,
     *         is_active: bool,
     *         sort_order: int,
     *         archived_at: null
     *     }>
     * }>
     */
    private function configuration(): array
    {
        return [
            [
                'key' => 'type',
                'label' => 'Type',
                'description' => null,
                'min_options' => 2,
                'max_options' => 10,
                'is_active' => true,
                'sort_order' => 10,
                'options' => [
                    $this->option('type_a', 'Type A', 10),
                    $this->option('type_b', 'Type B', 20),
                ],
            ],
            [
                'key' => 'attribute',
                'label' => 'Attribute',
                'description' => null,
                'min_options' => 2,
                'max_options' => 10,
                'is_active' => true,
                'sort_order' => 20,
                'options' => [
                    $this->option('attribute_a', 'Attribute A', 10),
                    $this->option('attribute_b', 'Attribute B', 20),
                    $this->option('attribute_c', 'Attribute C', 30),
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: null,
     *     is_active: true,
     *     sort_order: int,
     *     archived_at: null
     * }
     */
    private function option(string $key, string $label, int $sortOrder): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => null,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'archived_at' => null,
        ];
    }
}
