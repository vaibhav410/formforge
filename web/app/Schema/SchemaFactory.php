<?php

declare(strict_types=1);

namespace App\Schema;

use App\Enums\FieldType;

/**
 * Canonical constructors for schema nodes. The builder palette, the
 * seeder and the import parsers all create fields through here so a
 * "new dropdown" is identical everywhere.
 */
final class SchemaFactory
{
    public static function emptySchema(string $title = 'Untitled form'): array
    {
        return [
            'schema_version' => FormSchema::VERSION,
            'title' => $title,
            'description' => null,
            'settings' => [
                'submit_label' => 'Submit',
                'success_message' => 'Thank you — your response has been recorded.',
            ],
            'sections' => [self::section('Section 1')],
        ];
    }

    public static function section(string $title, array $overrides = []): array
    {
        return array_replace([
            'id' => SchemaSanitizer::newId('sec'),
            'title' => $title,
            'description' => null,
            'fields' => [],
        ], $overrides);
    }

    public static function field(FieldType $type, array $overrides = []): array
    {
        $field = [
            'id' => SchemaSanitizer::newId('fld'),
            'key' => '', // caller (or sanitizer) derives from label
            'type' => $type->value,
            'label' => $type->label(),
            'description' => null,
            'placeholder' => null,
            'required' => false,
            'default' => null,
            'options' => $type->hasOptions()
                ? [
                    ['label' => 'Option 1', 'value' => 'option_1'],
                    ['label' => 'Option 2', 'value' => 'option_2'],
                ]
                : null,
            'validation' => [
                'min' => null,
                'max' => null,
                'min_length' => null,
                'max_length' => null,
                'regex' => null,
                'mimes' => $type === FieldType::File ? ['pdf', 'doc', 'docx', 'png', 'jpg'] : null,
                'max_size_kb' => $type === FieldType::File ? 5120 : null,
                'multiple' => null,
            ],
            'css_class' => null,
            'hidden' => false,
            'logic' => null,
            'meta' => match ($type) {
                FieldType::Rating => ['rating_max' => 5],
                FieldType::Textarea => ['rows' => 4],
                FieldType::Heading => ['heading_level' => 'h2'],
                default => [],
            },
        ];

        return array_replace($field, $overrides);
    }
}
