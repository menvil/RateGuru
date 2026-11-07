<?php

namespace Database\Factories;

use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Models\PersonaProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonaProfile>
 */
class PersonaProfileFactory extends Factory
{
    /**
     * A profile with its account: a Persona made as UserFactory::persona()
     * makes one. Production Personas are only ever made by
     * CreatePersonaAction.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->persona(),
            'short_description' => fake()->sentence(),
            'character' => fake()->paragraph(),
            'speech_style' => fake()->sentence(),
            'topics' => fake()->sentence(),
            'never_writes_about' => fake()->sentence(),
            'emoji_frequency' => PersonaEmojiFrequency::None,
            'engagement' => PersonaEngagement::Off,
            'max_replies_per_day' => 0,
            'created_by_user_id' => null,
        ];
    }
}
