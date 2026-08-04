<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Submission> */
class SubmissionFactory extends Factory
{
    public function definition(): array
    {
        $submittedAt = fake()->dateTimeBetween('-30 days');

        return [
            'form_id' => Form::factory(),
            'form_version_id' => FormVersion::factory(),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'user_agent' => fake()->userAgent(),
            'referrer' => fake()->optional()->url(),
            'started_at' => (clone $submittedAt)->modify('-'.fake()->numberBetween(30, 600).' seconds'),
            'submitted_at' => $submittedAt,
            'duration_seconds' => fake()->numberBetween(30, 600),
        ];
    }
}
