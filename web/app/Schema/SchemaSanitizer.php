<?php

declare(strict_types=1);

namespace App\Schema;

use App\Enums\FieldType;
use Illuminate\Support\Str;

/**
 * Normalises untrusted schema JSON (browser JSON editor, LLM output,
 * document imports) into canonical shape before validation:
 *
 *  - drops unknown properties at every level
 *  - generates missing section/field ids and slug keys
 *  - de-duplicates field keys
 *  - strips HTML from every human-readable string
 *  - coerces booleans/numbers, clamps string lengths
 *  - in lenient mode, additionally repairs what a strict save would
 *    reject (unknown types → text, missing options → placeholder,
 *    logic referring to unknown fields → dropped)
 *
 * Sanitize never *invents* meaning; anything it cannot fix in lenient
 * mode is left for the validator to reject.
 */
final class SchemaSanitizer
{
    private const FIELD_KEYS = [
        'id', 'key', 'type', 'label', 'description', 'placeholder', 'required',
        'default', 'options', 'validation', 'css_class', 'hidden', 'logic', 'meta',
    ];

    private const VALIDATION_KEYS = [
        'min', 'max', 'min_length', 'max_length', 'regex', 'mimes', 'max_size_kb', 'multiple',
    ];

    private const CONDITION_OPERATORS = [
        'equals', 'not_equals', 'contains', 'greater_than', 'less_than', 'is_empty', 'is_not_empty',
    ];

    public function sanitize(array $raw, bool $lenient = false): array
    {
        $clean = [
            'schema_version' => FormSchema::VERSION,
            'title' => $this->cleanString($raw['title'] ?? null, 255) ?? 'Untitled form',
            'description' => $this->cleanString($raw['description'] ?? null, 2000),
            'settings' => $this->sanitizeSettings(is_array($raw['settings'] ?? null) ? $raw['settings'] : []),
            'sections' => [],
        ];

        $sections = is_array($raw['sections'] ?? null) ? $raw['sections'] : [];
        $seenKeys = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $clean['sections'][] = $this->sanitizeSection($section, $seenKeys, $lenient);
        }

        // A schema with no sections still needs somewhere to drop fields.
        if ($clean['sections'] === []) {
            $clean['sections'][] = [
                'id' => self::newId('sec'),
                'title' => 'Section 1',
                'description' => null,
                'fields' => [],
            ];
        }

        if ($lenient) {
            $clean = $this->dropBrokenLogic($clean);
        }

        return $clean;
    }

    public static function newId(string $prefix): string
    {
        return $prefix.'_'.Str::lower(Str::random(8));
    }

    private function sanitizeSettings(array $settings): array
    {
        return [
            'submit_label' => $this->cleanString($settings['submit_label'] ?? null, 60) ?? 'Submit',
            'success_message' => $this->cleanString($settings['success_message'] ?? null, 1000)
                ?? 'Thank you — your response has been recorded.',
        ];
    }

    private function sanitizeSection(array $section, array &$seenKeys, bool $lenient): array
    {
        $fields = [];
        foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
            if (! is_array($field)) {
                continue;
            }
            $fields[] = $this->sanitizeField($field, $seenKeys, $lenient);
        }

        return [
            'id' => $this->cleanId($section['id'] ?? null, 'sec'),
            'title' => $this->cleanString($section['title'] ?? null, 255) ?? 'Section',
            'description' => $this->cleanString($section['description'] ?? null, 2000),
            'fields' => $fields,
        ];
    }

    private function sanitizeField(array $field, array &$seenKeys, bool $lenient): array
    {
        $field = array_intersect_key($field, array_flip(self::FIELD_KEYS));

        $type = FieldType::tryFrom(is_string($field['type'] ?? null) ? strtolower($field['type']) : '');
        if ($type === null && $lenient) {
            // LLMs occasionally hallucinate types ("multiselect", "fullname");
            // map the common ones, fall back to text.
            $type = $this->guessType((string) ($field['type'] ?? ''));
        }

        $label = $this->cleanString($field['label'] ?? null, 255) ?? 'Untitled field';
        $key = $this->cleanKey($field['key'] ?? null) ?? Str::slug($label, '_');
        $key = $this->uniqueKey($key !== '' ? $key : 'field', $seenKeys);
        $seenKeys[] = $key;

        $clean = [
            'id' => $this->cleanId($field['id'] ?? null, 'fld'),
            'key' => $key,
            'type' => $type?->value ?? (string) ($field['type'] ?? 'unknown'),
            'label' => $label,
            'description' => $this->cleanString($field['description'] ?? null, 1000),
            'placeholder' => $this->cleanString($field['placeholder'] ?? null, 255),
            'required' => (bool) ($field['required'] ?? false),
            'default' => $this->sanitizeDefault($field['default'] ?? null),
            'options' => $this->sanitizeOptions($field['options'] ?? null, $type, $lenient),
            'validation' => $this->sanitizeValidation(is_array($field['validation'] ?? null) ? $field['validation'] : []),
            'css_class' => $this->cleanString($field['css_class'] ?? null, 255),
            'hidden' => (bool) ($field['hidden'] ?? false),
            'logic' => $this->sanitizeLogic($field['logic'] ?? null),
            'meta' => $this->sanitizeMeta(is_array($field['meta'] ?? null) ? $field['meta'] : []),
        ];

        return $clean;
    }

    private function guessType(string $raw): FieldType
    {
        $raw = strtolower(trim($raw));

        return match (true) {
            // "multi"/"check" must win over the bare "select" substring:
            // "multiselect" is a checkbox, not a dropdown.
            str_contains($raw, 'check') || str_contains($raw, 'multi') => FieldType::Checkbox,
            str_contains($raw, 'select') || str_contains($raw, 'choice') => FieldType::Dropdown,
            str_contains($raw, 'radio') => FieldType::Radio,
            str_contains($raw, 'area') || str_contains($raw, 'long') || str_contains($raw, 'paragraph') => FieldType::Textarea,
            str_contains($raw, 'mail') => FieldType::Email,
            str_contains($raw, 'phone') || str_contains($raw, 'tel') || str_contains($raw, 'mobile') => FieldType::Phone,
            str_contains($raw, 'date') => FieldType::Date,
            str_contains($raw, 'time') => FieldType::Time,
            str_contains($raw, 'file') || str_contains($raw, 'upload') || str_contains($raw, 'attach')
                || str_contains($raw, 'resume') || str_contains($raw, 'cv') => FieldType::File,
            str_contains($raw, 'num') || str_contains($raw, 'int') || str_contains($raw, 'float')
                || str_contains($raw, 'decimal') || str_contains($raw, 'age') => FieldType::Number,
            str_contains($raw, 'rat') || str_contains($raw, 'star') || str_contains($raw, 'scale') => FieldType::Rating,
            str_contains($raw, 'url') || str_contains($raw, 'link') || str_contains($raw, 'website') => FieldType::Url,
            str_contains($raw, 'address') => FieldType::Address,
            str_contains($raw, 'head') || str_contains($raw, 'title') || str_contains($raw, 'label') => FieldType::Heading,
            str_contains($raw, 'color') || str_contains($raw, 'colour') => FieldType::Color,
            str_contains($raw, 'sign') => FieldType::Signature,
            str_contains($raw, 'hidden') => FieldType::Hidden,
            str_contains($raw, 'pass') => FieldType::Password,
            default => FieldType::Text,
        };
    }

    private function sanitizeOptions(mixed $options, ?FieldType $type, bool $lenient): ?array
    {
        if ($type === null || ! $type->hasOptions()) {
            return null;
        }

        $clean = [];
        $seenValues = [];

        foreach (is_array($options) ? $options : [] as $option) {
            // Accept both {label,value} objects and bare strings from AI/imports.
            if (is_string($option) || is_numeric($option)) {
                $option = ['label' => (string) $option];
            }
            if (! is_array($option)) {
                continue;
            }

            $label = $this->cleanString($option['label'] ?? $option['value'] ?? null, 255);
            if ($label === null) {
                continue;
            }

            $value = is_scalar($option['value'] ?? null)
                ? Str::limit(strip_tags((string) $option['value']), 255, '')
                : Str::slug($label, '_');
            if ($value === '') {
                $value = Str::slug($label, '_') ?: 'option';
            }

            $value = $this->uniqueKey($value, $seenValues);
            $seenValues[] = $value;

            $clean[] = ['label' => $label, 'value' => $value];
        }

        if ($clean === [] && $lenient) {
            $clean = [['label' => 'Option 1', 'value' => 'option_1']];
        }

        return $clean;
    }

    private function sanitizeValidation(array $validation): array
    {
        $validation = array_intersect_key($validation, array_flip(self::VALIDATION_KEYS));

        $out = [];
        foreach (['min', 'max'] as $k) {
            $out[$k] = is_numeric($validation[$k] ?? null) ? $validation[$k] + 0 : null;
        }
        foreach (['min_length', 'max_length', 'max_size_kb'] as $k) {
            $out[$k] = is_numeric($validation[$k] ?? null) ? max(0, (int) $validation[$k]) : null;
        }

        $regex = $validation['regex'] ?? null;
        $out['regex'] = (is_string($regex) && $regex !== '' && FormSchemaValidator::regexCompiles($regex))
            ? $regex
            : null;

        $mimes = $validation['mimes'] ?? null;
        $out['mimes'] = is_array($mimes)
            ? array_values(array_filter(array_map(
                fn ($m) => is_string($m) ? strtolower(preg_replace('/[^a-z0-9]/', '', strtolower($m))) : null,
                $mimes
            )))
            : null;
        if ($out['mimes'] === []) {
            $out['mimes'] = null;
        }

        $out['multiple'] = isset($validation['multiple']) ? (bool) $validation['multiple'] : null;

        return $out;
    }

    private function sanitizeLogic(mixed $logic): ?array
    {
        if (! is_array($logic)) {
            return null;
        }

        $conditions = [];
        foreach (is_array($logic['conditions'] ?? null) ? $logic['conditions'] : [] as $condition) {
            if (! is_array($condition)) {
                continue;
            }
            $field = $this->cleanKey($condition['field'] ?? null);
            $operator = $condition['operator'] ?? null;
            if ($field === null || ! in_array($operator, self::CONDITION_OPERATORS, true)) {
                continue;
            }
            $value = $condition['value'] ?? null;
            $conditions[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => is_scalar($value) || $value === null ? $value : null,
            ];
        }

        if ($conditions === []) {
            return null;
        }

        return [
            'action' => in_array($logic['action'] ?? null, ['show', 'hide'], true) ? $logic['action'] : 'show',
            'match' => in_array($logic['match'] ?? null, ['all', 'any'], true) ? $logic['match'] : 'all',
            'conditions' => $conditions,
        ];
    }

    private function sanitizeMeta(array $meta): array
    {
        $out = [];
        if (is_numeric($meta['rating_max'] ?? null)) {
            $out['rating_max'] = min(10, max(2, (int) $meta['rating_max']));
        }
        if (is_numeric($meta['rows'] ?? null)) {
            $out['rows'] = min(20, max(2, (int) $meta['rows']));
        }
        if (is_numeric($meta['step'] ?? null)) {
            $out['step'] = $meta['step'] + 0;
        }
        if (in_array($meta['heading_level'] ?? null, ['h2', 'h3'], true)) {
            $out['heading_level'] = $meta['heading_level'];
        }

        return $out;
    }

    private function sanitizeDefault(mixed $default): mixed
    {
        if (is_scalar($default) || $default === null) {
            return is_string($default) ? Str::limit(strip_tags($default), 1000, '') : $default;
        }
        if (is_array($default)) {
            return array_values(array_filter(array_map(
                fn ($v) => is_scalar($v) ? Str::limit(strip_tags((string) $v), 255, '') : null,
                $default
            ), fn ($v) => $v !== null));
        }

        return null;
    }

    /** Drop logic conditions that reference fields that don't exist. */
    private function dropBrokenLogic(array $schema): array
    {
        $keys = FormSchema::fromArray($schema)->fieldKeys();

        foreach ($schema['sections'] as $si => $section) {
            foreach ($section['fields'] as $fi => $field) {
                if (! isset($field['logic'])) {
                    continue;
                }
                $logic = $field['logic'];
                if ($logic === null) {
                    continue;
                }
                $logic['conditions'] = array_values(array_filter(
                    $logic['conditions'],
                    // Self-reference is as broken as a missing key.
                    fn (array $c) => in_array($c['field'], $keys, true) && $c['field'] !== $field['key']
                ));
                $schema['sections'][$si]['fields'][$fi]['logic'] =
                    $logic['conditions'] === [] ? null : $logic;
            }
        }

        return $schema;
    }

    private function cleanString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : Str::limit($value, $maxLength, '');
    }

    private function cleanId(mixed $id, string $prefix): string
    {
        if (is_string($id) && preg_match('/^'.$prefix.'_[a-z0-9]{4,16}$/', $id)) {
            return $id;
        }

        return self::newId($prefix);
    }

    private function cleanKey(mixed $key): ?string
    {
        if (! is_string($key)) {
            return null;
        }
        $key = Str::slug($key, '_');

        return $key === '' ? null : Str::limit($key, 64, '');
    }

    private function uniqueKey(string $key, array $seen): string
    {
        $candidate = $key;
        $i = 2;
        while (in_array($candidate, $seen, true)) {
            $candidate = $key.'_'.$i;
            $i++;
        }

        return $candidate;
    }
}
