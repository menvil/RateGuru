<?php

namespace Database\Factories;

use App\Enums\TrackingLinkDestination;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackingLink>
 */
class TrackingLinkFactory extends Factory
{
    /** A link to the home feed for a Reddit post, as an administrator saved it. */
    public function definition(): array
    {
        $post = 'post_'.fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => 'Reddit · launch · '.$post,
            'destination' => TrackingLinkDestination::Page,
            'game_id' => null,
            'path' => '/',
            'utm_source' => 'reddit',
            'utm_medium' => 'organic_social',
            'utm_campaign' => 'launch',
            'utm_content' => $post,
            'utm_term' => '',
            'created_by_user_id' => User::factory()->admin(),
            'lock_version' => 1,
        ];
    }
}
