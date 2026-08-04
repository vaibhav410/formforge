<?php

declare(strict_types=1);

namespace App\Schema;

/**
 * Evaluates conditional logic server-side against submitted input.
 * The same rules run client-side (Alpine) for UX, but this is the
 * authority: fields hidden by logic are neither validated nor stored,
 * whatever the browser sent.
 */
final class ConditionEvaluator
{
    /**
     * Keys of fields visible for the given input.
     *
     * @param  array<string, mixed>  $input  raw answers keyed by field key
     * @return list<string>
     */
    public function visibleFieldKeys(FormSchema $schema, array $input): array
    {
        $visible = [];
        foreach ($schema->fields() as $field) {
            if ($this->isVisible($field, $input)) {
                $visible[] = $field['key'];
            }
        }

        return $visible;
    }

    public function isVisible(array $field, array $input): bool
    {
        // Statically hidden fields (type "hidden" is still submitted).
        if (($field['hidden'] ?? false) === true && ($field['type'] ?? null) !== 'hidden') {
            return false;
        }

        $logic = $field['logic'] ?? null;
        if ($logic === null) {
            return true;
        }

        $results = array_map(
            fn (array $c) => $this->evaluateCondition($c, $input),
            $logic['conditions'] ?? []
        );

        $matched = ($logic['match'] ?? 'all') === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);

        return ($logic['action'] ?? 'show') === 'show' ? $matched : ! $matched;
    }

    private function evaluateCondition(array $condition, array $input): bool
    {
        $actual = $input[$condition['field'] ?? ''] ?? null;
        $expected = $condition['value'] ?? null;

        return match ($condition['operator'] ?? '') {
            'equals' => $this->looselyEquals($actual, $expected),
            'not_equals' => ! $this->looselyEquals($actual, $expected),
            'contains' => $this->contains($actual, $expected),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && $actual + 0 > $expected + 0,
            'less_than' => is_numeric($actual) && is_numeric($expected) && $actual + 0 < $expected + 0,
            'is_empty' => $this->isEmpty($actual),
            'is_not_empty' => ! $this->isEmpty($actual),
            default => false,
        };
    }

    private function looselyEquals(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            // Checkbox answers: "equals X" reads as "X is selected".
            return in_array((string) $expected, array_map('strval', $actual), true);
        }

        return (string) ($actual ?? '') === (string) ($expected ?? '');
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array((string) $expected, array_map('strval', $actual), true);
        }

        return is_scalar($actual) && $expected !== null && $expected !== ''
            && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected));
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
