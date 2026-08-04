<?php

namespace Database\Factories;

use App\Enums\FieldType;
use App\Enums\VersionSource;
use App\Enums\VersionStatus;
use App\Models\Form;
use App\Schema\SchemaFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\FormVersion> */
class FormVersionFactory extends Factory
{
    public function definition(): array
    {
        $schema = SchemaFactory::emptySchema(fake()->sentence(3));
        $schema['sections'][0]['fields'] = [
            SchemaFactory::field(FieldType::Text, ['key' => 'full_name', 'label' => 'Full name', 'required' => true]),
            SchemaFactory::field(FieldType::Email, ['key' => 'email', 'label' => 'Email', 'required' => true]),
        ];

        return [
            'form_id' => Form::factory(),
            'version' => 1,
            'schema_json' => $schema,
            'status' => VersionStatus::Draft,
            'source' => VersionSource::Manual,
            'label' => 'Initial version',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => VersionStatus::Published,
            'published_at' => now(),
        ]);
    }
}
