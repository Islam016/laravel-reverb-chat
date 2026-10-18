<?php

namespace Database\Factories;

use App\Enums\ConversationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ConversationType::Group,
            'name' => fake()->words(2, true),
        ];
    }
}
