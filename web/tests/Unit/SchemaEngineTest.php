<?php

use App\Enums\FieldType;
use App\Schema\ConditionEvaluator;
use App\Schema\FormSchema;
use App\Schema\FormSchemaValidator;
use App\Schema\SchemaFactory;
use App\Schema\SchemaSanitizer;
use App\Schema\ValidationRuleCompiler;

function makeSchema(array $fields): array
{
    $schema = SchemaFactory::emptySchema('Test form');
    $schema['sections'][0]['fields'] = $fields;

    return $schema;
}

// ── Sanitizer ────────────────────────────────────────────────────

test('sanitizer strips html from labels and descriptions', function () {
    $schema = (new SchemaSanitizer())->sanitize(makeSchema([
        SchemaFactory::field(FieldType::Text, [
            'key' => 'name',
            'label' => '<script>alert(1)</script>Name',
            'description' => '<b>bold</b> help',
        ]),
    ]));

    $field = $schema['sections'][0]['fields'][0];
    expect($field['label'])->toBe('alert(1)Name')
        ->and($field['description'])->toBe('bold help');
});

test('sanitizer dedupes field keys', function () {
    $schema = (new SchemaSanitizer())->sanitize(makeSchema([
        SchemaFactory::field(FieldType::Text, ['key' => 'email', 'label' => 'Email one']),
        SchemaFactory::field(FieldType::Text, ['key' => 'email', 'label' => 'Email two']),
    ]));

    $keys = array_column($schema['sections'][0]['fields'], 'key');
    expect($keys)->toBe(['email', 'email_2']);
});

test('numbered and non-latin labels still produce contract-valid keys', function () {
    // Real-world questionnaire labels: numbered questions slug into
    // digit-leading keys, Devanagari slugs into nothing at all.
    $schema = (new SchemaSanitizer())->sanitize(makeSchema([
        ['type' => 'text', 'label' => '1. Your Name', 'key' => '1_your_name'],
        ['type' => 'email', 'label' => '2. Email Address'],
        ['type' => 'text', 'label' => 'नाम लिखिए'],
        ['type' => 'text', 'label' => '???'],
    ]), lenient: true);

    $keys = array_column($schema['sections'][0]['fields'], 'key');
    foreach ($keys as $key) {
        expect($key)->toMatch('/^[a-z][a-z0-9_]{0,63}$/');
    }
    expect($keys[0])->toBe('your_name')
        ->and($keys[1])->toBe('email_address')
        ->and($keys)->toBe(array_unique($keys))
        ->and((new FormSchemaValidator())->validate($schema))->toBe([]);
});

test('sanitizer derives a key from the label when missing', function () {
    $schema = (new SchemaSanitizer())->sanitize(makeSchema([
        ['type' => 'text', 'label' => 'Your Full Name'],
    ]));

    expect($schema['sections'][0]['fields'][0]['key'])->toBe('your_full_name');
});

test('lenient mode maps hallucinated types instead of failing', function () {
    $schema = (new SchemaSanitizer())->sanitize(makeSchema([
        ['type' => 'multiselect', 'label' => 'Pick some', 'options' => ['A', 'B']],
        ['type' => 'fullname', 'label' => 'Someone'],
    ]), lenient: true);

    $types = array_column($schema['sections'][0]['fields'], 'type');
    expect($types[0])->toBe('checkbox')->and($types[1])->toBe('text');
});

test('lenient mode accepts bare string options and drops broken logic refs', function () {
    $schema = (new SchemaSanitizer())->sanitize(makeSchema([
        ['type' => 'dropdown', 'label' => 'Colour', 'key' => 'colour', 'options' => ['Red', 'Blue']],
        ['type' => 'text', 'label' => 'Why', 'key' => 'why', 'logic' => [
            'action' => 'show', 'match' => 'all',
            'conditions' => [['field' => 'ghost_field', 'operator' => 'equals', 'value' => 'x']],
        ]],
    ]), lenient: true);

    $fields = $schema['sections'][0]['fields'];
    expect($fields[0]['options'])->toBe([
        ['label' => 'Red', 'value' => 'red'],
        ['label' => 'Blue', 'value' => 'blue'],
    ])->and($fields[1]['logic'])->toBeNull();
});

// ── Validator ────────────────────────────────────────────────────

test('a sanitized factory schema validates clean', function () {
    $schema = (new SchemaSanitizer())->sanitize(makeSchema([
        SchemaFactory::field(FieldType::Email, ['key' => 'email', 'label' => 'Email']),
        SchemaFactory::field(FieldType::Dropdown, ['key' => 'role', 'label' => 'Role']),
    ]));

    expect((new FormSchemaValidator())->validate($schema))->toBe([]);
});

test('validator rejects duplicate keys and unknown types with paths', function () {
    $schema = makeSchema([
        SchemaFactory::field(FieldType::Text, ['key' => 'a', 'label' => 'A']),
        array_replace(SchemaFactory::field(FieldType::Text, ['key' => 'a', 'label' => 'B']), ['type' => 'magic']),
    ]);

    $errors = (new FormSchemaValidator())->validate($schema);
    $paths = array_column($errors, 'path');

    expect($paths)->toContain('sections.0.fields.1.type')
        ->and($paths)->toContain('sections.0.fields.1.key');
});

test('validator rejects choice fields without options', function () {
    $field = SchemaFactory::field(FieldType::Radio, ['key' => 'r', 'label' => 'R']);
    $field['options'] = [];

    $errors = (new FormSchemaValidator())->validate(makeSchema([$field]));
    expect(array_column($errors, 'path'))->toContain('sections.0.fields.0.options');
});

test('validator rejects logic referencing unknown or self fields', function () {
    $field = SchemaFactory::field(FieldType::Text, ['key' => 'a', 'label' => 'A', 'logic' => [
        'action' => 'show', 'match' => 'all',
        'conditions' => [['field' => 'a', 'operator' => 'equals', 'value' => '1']],
    ]]);

    $errors = (new FormSchemaValidator())->validate(makeSchema([$field]));
    expect(implode(' ', array_column($errors, 'message')))->toContain('cannot depend on itself');
});

test('validator rejects a regex that does not compile', function () {
    $field = SchemaFactory::field(FieldType::Text, ['key' => 'a', 'label' => 'A']);
    $field['validation']['regex'] = '([a-z';

    $errors = (new FormSchemaValidator())->validate(makeSchema([$field]));
    expect(array_column($errors, 'path'))->toContain('sections.0.fields.0.validation.regex');
});

// ── Condition evaluator ──────────────────────────────────────────

test('condition operators evaluate correctly', function () {
    $evaluator = new ConditionEvaluator();
    $field = fn (array $logic) => ['key' => 'x', 'type' => 'text', 'logic' => $logic];
    $show = fn (string $op, mixed $value) => [
        'action' => 'show', 'match' => 'all',
        'conditions' => [['field' => 'dep', 'operator' => $op, 'value' => $value]],
    ];

    expect($evaluator->isVisible($field($show('equals', 'yes')), ['dep' => 'yes']))->toBeTrue()
        ->and($evaluator->isVisible($field($show('equals', 'yes')), ['dep' => 'no']))->toBeFalse()
        ->and($evaluator->isVisible($field($show('equals', 'php')), ['dep' => ['php', 'sql']]))->toBeTrue()
        ->and($evaluator->isVisible($field($show('greater_than', 3)), ['dep' => '5']))->toBeTrue()
        ->and($evaluator->isVisible($field($show('less_than', 3)), ['dep' => '5']))->toBeFalse()
        ->and($evaluator->isVisible($field($show('is_empty', null)), []))->toBeTrue()
        ->and($evaluator->isVisible($field($show('is_not_empty', null)), ['dep' => 'x']))->toBeTrue()
        ->and($evaluator->isVisible($field($show('contains', 'ell')), ['dep' => 'Hello']))->toBeTrue();
});

test('hide action inverts the match', function () {
    $evaluator = new ConditionEvaluator();
    $field = ['key' => 'x', 'type' => 'text', 'logic' => [
        'action' => 'hide', 'match' => 'all',
        'conditions' => [['field' => 'dep', 'operator' => 'equals', 'value' => 'secret']],
    ]];

    expect($evaluator->isVisible($field, ['dep' => 'secret']))->toBeFalse()
        ->and($evaluator->isVisible($field, ['dep' => 'other']))->toBeTrue();
});

// ── Rule compiler ────────────────────────────────────────────────

test('compiler derives rules from the schema and skips hidden-by-logic fields', function () {
    $schema = FormSchema::fromArray((new SchemaSanitizer())->sanitize(makeSchema([
        SchemaFactory::field(FieldType::Email, ['key' => 'email', 'label' => 'Email', 'required' => true]),
        SchemaFactory::field(FieldType::Textarea, ['key' => 'details', 'label' => 'Details', 'required' => true, 'logic' => [
            'action' => 'show', 'match' => 'all',
            'conditions' => [['field' => 'email', 'operator' => 'is_not_empty', 'value' => null]],
        ]]),
    ])));

    $compiler = new ValidationRuleCompiler();

    $withEmail = $compiler->compile($schema, ['email' => 'a@b.com', 'details' => 'x']);
    expect($withEmail['visible'])->toContain('details')
        ->and($withEmail['rules']['email'])->toContain('required')
        ->and($withEmail['rules']['email'])->toContain('email:rfc');

    $withoutEmail = $compiler->compile($schema, []);
    expect($withoutEmail['visible'])->not->toContain('details')
        ->and($withoutEmail['rules'])->not->toHaveKey('details');
});

test('compiler builds checkbox array rules with option whitelist', function () {
    $schema = FormSchema::fromArray((new SchemaSanitizer())->sanitize(makeSchema([
        SchemaFactory::field(FieldType::Checkbox, [
            'key' => 'skills', 'label' => 'Skills', 'required' => true,
            'options' => [['label' => 'PHP', 'value' => 'php'], ['label' => 'SQL', 'value' => 'sql']],
        ]),
    ])));

    $compiled = (new ValidationRuleCompiler())->compile($schema, []);
    expect($compiled['rules'])->toHaveKeys(['skills', 'skills.*'])
        ->and($compiled['rules']['skills'])->toContain('array');
});
