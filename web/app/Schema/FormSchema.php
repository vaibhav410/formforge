<?php

declare(strict_types=1);

namespace App\Schema;

use App\Enums\FieldType;

/**
 * Immutable value object over the canonical form schema array.
 *
 * The array shape (documented in docs/SCHEMA.md and mirrored by the
 * AI service's Pydantic models):
 *
 * {
 *   "schema_version": 1,
 *   "title": "...",
 *   "description": null,
 *   "settings": { "submit_label": "...", "success_message": "..." },
 *   "sections": [
 *     { "id": "sec_xxxxxxxx", "title": "...", "description": null,
 *       "fields": [ { "id": "fld_xxxxxxxx", "key": "...", "type": "...", ... } ] }
 *   ]
 * }
 */
final readonly class FormSchema
{
    public const VERSION = 1;

    public function __construct(private array $data)
    {
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function title(): string
    {
        return (string) ($this->data['title'] ?? 'Untitled form');
    }

    public function description(): ?string
    {
        return $this->data['description'] ?? null;
    }

    /** @return array<int, array> */
    public function sections(): array
    {
        return $this->data['sections'] ?? [];
    }

    public function settings(): array
    {
        return ($this->data['settings'] ?? []) + [
            'submit_label' => 'Submit',
            'success_message' => 'Thank you — your response has been recorded.',
        ];
    }

    /**
     * All fields across sections, in render order.
     *
     * @return array<int, array>
     */
    public function fields(): array
    {
        $fields = [];
        foreach ($this->sections() as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** Fields that collect an answer (layout types excluded). */
    public function answerableFields(): array
    {
        return array_values(array_filter(
            $this->fields(),
            function (array $field): bool {
                $type = FieldType::tryFrom($field['type'] ?? '');

                return $type !== null && $type->collectsAnswer();
            }
        ));
    }

    public function field(string $key): ?array
    {
        foreach ($this->fields() as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function fieldKeys(): array
    {
        return array_values(array_filter(array_map(
            fn (array $f) => $f['key'] ?? null,
            $this->fields()
        )));
    }
}
