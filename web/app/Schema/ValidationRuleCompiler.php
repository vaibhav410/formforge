<?php

declare(strict_types=1);

namespace App\Schema;

use App\Enums\FieldType;
use Illuminate\Validation\Rule;

/**
 * Compiles the form schema into Laravel validation rules for public
 * submissions. This is where "server-side validation is derived from
 * the same schema" is made literal: there is no second definition of
 * any rule anywhere.
 *
 * Fields hidden by conditional logic (per ConditionEvaluator, using
 * the submitted input itself) are excluded entirely — their values
 * are discarded, not validated.
 */
final class ValidationRuleCompiler
{
    public function __construct(
        private readonly ConditionEvaluator $conditions = new ConditionEvaluator(),
    ) {
    }

    /**
     * @param  array<string, mixed>  $input  submitted answers keyed by field key
     * @return array{rules: array<string, list<mixed>>, attributes: array<string, string>, visible: list<string>}
     */
    public function compile(FormSchema $schema, array $input): array
    {
        $rules = [];
        $attributes = [];
        $visible = [];

        foreach ($schema->answerableFields() as $field) {
            $type = FieldType::from($field['type']);
            $key = $field['key'];

            if (! $this->conditions->isVisible($field, $input)) {
                continue;
            }
            $visible[] = $key;

            foreach ($this->rulesForField($field, $type) as $dataKey => $fieldRules) {
                $rules[$dataKey] = $fieldRules;
                $attributes[$dataKey] = mb_strtolower($field['label']);
            }
        }

        return ['rules' => $rules, 'attributes' => $attributes, 'visible' => $visible];
    }

    /** @return array<string, list<mixed>> rules keyed by data key (usually the field key) */
    private function rulesForField(array $field, FieldType $type): array
    {
        $key = $field['key'];
        $v = $field['validation'] ?? [];
        $required = ($field['required'] ?? false) === true;

        $base = $required ? ['required'] : ['nullable'];

        return match ($type) {
            FieldType::Text, FieldType::Password => [$key => [
                ...$base, 'string',
                ...$this->lengthRules($v, defaultMax: 1000),
                ...$this->regexRule($v),
            ]],
            FieldType::Textarea => [$key => [
                ...$base, 'string',
                ...$this->lengthRules($v, defaultMax: 10000),
                ...$this->regexRule($v),
            ]],
            FieldType::Number => [$key => [
                ...$base, 'numeric',
                ...(isset($v['min']) && is_numeric($v['min']) ? ['min:'.$v['min']] : []),
                ...(isset($v['max']) && is_numeric($v['max']) ? ['max:'.$v['max']] : []),
            ]],
            FieldType::Email => [$key => [
                ...$base, 'string', 'email:rfc', 'max:320',
            ]],
            FieldType::Phone => [$key => [
                ...$base, 'string', 'max:30',
                'regex:'.($this->userRegex($v) ?? '/^\+?[0-9 ().\-]{5,25}$/'),
            ]],
            FieldType::Date => [$key => [
                ...$base, 'date',
                ...(isset($v['min']) && is_string($v['min']) ? ['after_or_equal:'.$v['min']] : []),
                ...(isset($v['max']) && is_string($v['max']) ? ['before_or_equal:'.$v['max']] : []),
            ]],
            FieldType::Time => [$key => [
                ...$base, 'date_format:H:i',
            ]],
            FieldType::Dropdown, FieldType::Radio => [$key => [
                ...$base, Rule::in($this->optionValues($field)),
            ]],
            FieldType::Checkbox => [
                $key => [...$base, 'array', ...($required ? ['min:1'] : [])],
                "$key.*" => [Rule::in($this->optionValues($field))],
            ],
            FieldType::File => [$key => [
                ...$base, 'file',
                ...($this->mimes($v) !== null ? ['mimes:'.implode(',', $this->mimes($v))] : []),
                'max:'.(is_numeric($v['max_size_kb'] ?? null) ? (int) $v['max_size_kb'] : 10240),
            ]],
            FieldType::Rating => [$key => [
                ...$base, 'integer', 'min:1',
                'max:'.(int) ($field['meta']['rating_max'] ?? 5),
            ]],
            FieldType::Address => [
                $key => [...$base, 'array'],
                "$key.line1" => [...$base, 'string', 'max:255'],
                "$key.line2" => ['nullable', 'string', 'max:255'],
                "$key.city" => [...$base, 'string', 'max:100'],
                "$key.state" => ['nullable', 'string', 'max:100'],
                "$key.postal_code" => [...$base, 'string', 'max:20'],
                "$key.country" => [...$base, 'string', 'max:100'],
            ],
            FieldType::Url => [$key => [
                ...$base, 'string', 'url', 'max:2000',
            ]],
            FieldType::Signature => [$key => [
                ...$base, 'string', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', 'max:200000',
            ]],
            FieldType::Color => [$key => [
                ...$base, 'string', 'regex:/^#[0-9a-fA-F]{6}$/',
            ]],
            FieldType::Hidden => [$key => [
                'nullable', 'string', 'max:500',
            ]],
            FieldType::Heading => [],
        };
    }

    /** @return list<string> */
    private function lengthRules(array $v, int $defaultMax): array
    {
        $rules = [];
        if (is_numeric($v['min_length'] ?? null)) {
            $rules[] = 'min:'.(int) $v['min_length'];
        }
        $rules[] = 'max:'.(is_numeric($v['max_length'] ?? null) ? (int) $v['max_length'] : $defaultMax);

        return $rules;
    }

    /** @return list<string> */
    private function regexRule(array $v): array
    {
        $regex = $this->userRegex($v);

        return $regex === null ? [] : ['regex:'.$regex];
    }

    /** Wrap the schema's bare pattern into a delimited PCRE. */
    private function userRegex(array $v): ?string
    {
        $pattern = $v['regex'] ?? null;
        if (! is_string($pattern) || $pattern === '') {
            return null;
        }

        return '/'.str_replace('/', '\/', $pattern).'/';
    }

    /** @return list<string>|null */
    private function mimes(array $v): ?array
    {
        $mimes = $v['mimes'] ?? null;

        return is_array($mimes) && $mimes !== [] ? array_map('strval', $mimes) : null;
    }

    /** @return list<string> */
    private function optionValues(array $field): array
    {
        return array_map(
            fn (array $o) => (string) $o['value'],
            $field['options'] ?? []
        );
    }
}
