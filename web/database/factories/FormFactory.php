<?php

namespace Database\Factories;

use App\Enums\FormStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Form> */
class FormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(10),
            'status' => FormStatus::Draft,
            'settings' => [
                'submit_label' => 'Submit',
                'success_message' => 'Thank you — your response has been recorded.',
            ],
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => FormStatus::Published,
            'published_at' => now(),
        ]);
    }
}
