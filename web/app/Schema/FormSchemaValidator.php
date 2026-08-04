<?php

declare(strict_types=1);

namespace App\Schema;

use App\Enums\FieldType;

/**
 * Structural + semantic validation of a form schema. Runs after
 * SchemaSanitizer on every save path (builder autosave, JSON editor,
 * AI result, import commit). A schema that fails here is never
 * persisted — the "never store a broken schema" guarantee.
 *
 * Errors carry JSON-ish paths so the JSON editor can point at the
 * offending node.
 */
final class FormSchemaValidator
{
    /** @return list<array{path: string, message: string}> */
    public function validate(array $schema): array
    {
        $errors = [];

        if (($schema['schema_version'] ?? null) !== FormSchema::VERSION) {
            $errors[] = self::error('schema_version', 'schema_version must be '.FormSchema::VERSION);
        }
        if (! is_string($schema['title'] ?? null) || trim($schema['title']) === '') {
            $errors[] = self::error('title', 'Form title is required.');
        }

        $sections = $schema['sections'] ?? null;
        if (! is_array($sections) || $sections === []) {
            $errors[] = self::error('sections', 'A form needs at least one section.');

            return $errors;
        }

        $seenKeys = [];
        $seenIds = [];
        foreach ($sections as $si => $section) {
            $errors = array_merge(
                $errors,
                $this->validateSection($section, "sections.$si", $seenKeys, $seenIds)
            );
        }

        // Cross-field checks need the full key set.
        $allKeys = $seenKeys;
        foreach ($sections as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                $errors = array_merge(
                    $errors,
                    $this->validateLogic($field, "sections.$si.fields.$fi", $allKeys)
                );
            }
        }

        return $errors;
    }

    public function isValid(array $schema): bool
    {
        return $this->validate($schema) === [];
    }

    /** @return list<array{path: string, message: string}> */
    private function validateSection(mixed $section, string $path, array &$seenKeys, array &$seenIds): array
    {
        if (! is_array($section)) {
            return [self::error($path, 'Section must be an object.')];
        }

        $errors = [];
        if (! is_string($section['title'] ?? null) || trim($section['title']) === '') {
            $errors[] = self::error("$path.title", 'Section title is required.');
        }

        $id = $section['id'] ?? null;
        if (! is_string($id) || $id === '') {
            $errors[] = self::error("$path.id", 'Section id is required.');
        } elseif (in_array($id, $seenIds, true)) {
            $errors[] = self::error("$path.id", "Duplicate id \"$id\".");
        } else {
            $seenIds[] = $id;
        }

        foreach ($section['fields'] ?? [] as $fi => $field) {
            $errors = array_merge(
                $errors,
                $this->validateField($field, "$path.fields.$fi", $seenKeys, $seenIds)
            );
        }

        return $errors;
    }

    /** @return list<array{path: string, message: string}> */
    private function validateField(mixed $field, string $path, array &$seenKeys, array &$seenIds): array
    {
        if (! is_array($field)) {
            return [self::error($path, 'Field must be an object.')];
        }

        $errors = [];

        $type = FieldType::tryFrom((string) ($field['type'] ?? ''));
        if ($type === null) {
            $errors[] = self::error(
                "$path.type",
                'Unknown field type "'.($field['type'] ?? '').'". Allowed: '.implode(', ', FieldType::values()).'.'
            );
        }

        $id = $field['id'] ?? null;
        if (! is_string($id) || $id === '') {
            $errors[] = self::error("$path.id", 'Field id is required.');
        } elseif (in_array($id, $seenIds, true)) {
            $errors[] = self::error("$path.id", "Duplicate id \"$id\".");
        } else {
            $seenIds[] = $id;
        }

        $key = $field['key'] ?? null;
        if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
            $errors[] = self::error("$path.key", 'Field key must be a snake_case slug (a-z, 0-9, _), max 64 chars.');
        } elseif (in_array($key, $seenKeys, true)) {
            $errors[] = self::error("$path.key", "Duplicate field key \"$key\" — keys must be unique across the form.");
        } else {
            $seenKeys[] = $key;
        }

        if (! is_string($field['label'] ?? null) || trim($field['label']) === '') {
            $errors[] = self::error("$path.label", 'Field label is required.');
        }

        if ($type?->hasOptions()) {
            $options = $field['options'] ?? null;
            if (! is_array($options) || $options === []) {
                $errors[] = self::error("$path.options", "A {$type->value} field needs at least one option.");
            } else {
                $values = [];
                foreach ($options as $oi => $option) {
                    if (! is_array($option) || ! is_string($option['label'] ?? null) || ! is_string($option['value'] ?? null)) {
                        $errors[] = self::error("$path.options.$oi", 'Option must be an object with string label and value.');

                        continue;
                    }
                    if (in_array($option['value'], $values, true)) {
                        $errors[] = self::error("$path.options.$oi.value", "Duplicate option value \"{$option['value']}\".");
                    }
                    $values[] = $option['value'];
                }
            }
        }

        $errors = array_merge($errors, $this->validateRules($field, $path, $type));

        return $errors;
    }

    /** @return list<array{path: string, message: string}> */
    private function validateRules(array $field, string $path, ?FieldType $type): array
    {
        $errors = [];
        $v = $field['validation'] ?? [];
        if (! is_array($v)) {
            return [self::error("$path.validation", 'validation must be an object.')];
        }

        $min = $v['min'] ?? null;
        $max = $v['max'] ?? null;
        if (is_numeric($min) && is_numeric($max) && $min > $max) {
            $errors[] = self::error("$path.validation", 'min cannot be greater than max.');
        }

        $minLength = $v['min_length'] ?? null;
        $maxLength = $v['max_length'] ?? null;
        if (is_numeric($minLength) && is_numeric($maxLength) && $minLength > $maxLength) {
            $errors[] = self::error("$path.validation", 'min_length cannot be greater than max_length.');
        }

        $regex = $v['regex'] ?? null;
        if ($regex !== null) {
            if (! is_string($regex) || @preg_match('/'.str_replace('/', '\/', $regex).'/', '') === false) {
                $errors[] = self::error("$path.validation.regex", 'Regex does not compile.');
            }
        }

        if ($type === FieldType::File && is_numeric($v['max_size_kb'] ?? null) && $v['max_size_kb'] > 51200) {
            $errors[] = self::error("$path.validation.max_size_kb", 'File size limit cannot exceed 50 MB.');
        }

        // Requiring a field that is always hidden is a trap for respondents.
        if (($field['hidden'] ?? false) === true
            && ($field['required'] ?? false) === true
            && ($field['logic'] ?? null) === null
            && $type !== FieldType::Hidden) {
            $errors[] = self::error("$path.required", 'A permanently hidden field cannot be required.');
        }

        return $errors;
    }

    /** @return list<array{path: string, message: string}> */
    private function validateLogic(array $field, string $path, array $allKeys): array
    {
        $logic = $field['logic'] ?? null;
        if ($logic === null) {
            return [];
        }
        if (! is_array($logic)) {
            return [self::error("$path.logic", 'logic must be an object or null.')];
        }

        $errors = [];
        if (! in_array($logic['action'] ?? null, ['show', 'hide'], true)) {
            $errors[] = self::error("$path.logic.action", 'logic.action must be "show" or "hide".');
        }
        if (! in_array($logic['match'] ?? null, ['all', 'any'], true)) {
            $errors[] = self::error("$path.logic.match", 'logic.match must be "all" or "any".');
        }

        $conditions = $logic['conditions'] ?? null;
        if (! is_array($conditions) || $conditions === []) {
            $errors[] = self::error("$path.logic.conditions", 'logic needs at least one condition.');

            return $errors;
        }

        foreach ($conditions as $ci => $condition) {
            $ref = $condition['field'] ?? null;
            if (! in_array($ref, $allKeys, true)) {
                $errors[] = self::error(
                    "$path.logic.conditions.$ci.field",
                    'Condition references unknown field "'.($ref ?? '').'".'
                );
            }
            if ($ref === ($field['key'] ?? null)) {
                $errors[] = self::error("$path.logic.conditions.$ci.field", 'A field cannot depend on itself.');
            }
        }

        return $errors;
    }

    /** @return array{path: string, message: string} */
    private static function error(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }
}
