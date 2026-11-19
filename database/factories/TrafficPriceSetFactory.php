<?php

namespace Database\Factories;

use App\Models\TrafficPriceSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficPriceSet>
 */
class TrafficPriceSetFactory extends Factory
{
    /** The starting prices, with one group for every country. */
    public function definition(): array
    {
        return [
            'account_price' => '0.5',
            'post_bonus' => '1',
            'returned_bonus' => '0.2',
            'login_price' => '0',
            'country_groups' => [['key' => 'rest', 'name' => 'Every country', 'percent' => 100, 'countries' => []]],
            'summary' => null,
            'created_at' => now(),
        ];
    }
}
