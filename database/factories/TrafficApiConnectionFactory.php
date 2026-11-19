<?php

namespace Database\Factories;

use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnector;
use App\Models\TrafficApiConnection;
use App\Models\TrafficSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficApiConnection>
 */
class TrafficApiConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_id' => TrafficSource::factory()->state(['connector' => TrafficConnector::PopAds]),
            'connector' => TrafficConnector::PopAds,
            'credentials' => ['api_key' => 'test-key-'.fake()->lexify('????????')],
            'account_label' => null,
            'status' => TrafficConnectionStatus::Connected,
            'failures' => 0,
            'connected_by_user_id' => User::factory()->admin(),
        ];
    }
}
