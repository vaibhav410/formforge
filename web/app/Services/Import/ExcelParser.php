<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\FieldType;
use App\Schema\SchemaFactory;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Deterministic .xlsx → draft schema parser supporting two layouts:
 *
 * 1. Structured question sheet (documented in README + samples/):
 *    a header row containing at least "label" (or "question" / "field")
 *    and "type"; optional: key, required, options (pipe or comma
 *    separated), placeholder, help, section, min, max.
 *
 * 2. Plain header-row sheet: row 1 = one column per field; any data
 *    rows below are used as samples for type inference.
 */
final class ExcelParser
{
    private const LABEL_HEADERS = ['label', 'question', 'field', 'field label', 'title'];

    public function __construct(private readonly LabelTypeInferencer $inferencer)
    {
    }

    /** @return array{schema: array, issues: list<array{block: string, reason: string}>} */
    public function parse(string $path): array
    {
        // Explicit Xlsx reader: without this, PhpSpreadsheet quietly
        // falls back to its CSV reader for arbitrary files, and garbage
        // gets "parsed" instead of rejected with a clear error.
        $reader = IOFactory::createReader('Xlsx');
        if (! $reader->canRead($path)) {
            throw new \RuntimeException('The file is not a valid .xlsx spreadsheet.');
        }
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray(null, true, true, false);
        $rows = array_values(array_filter(
            array_map(fn (array $row) => array_map(
                fn ($cell) => $cell === null ? '' : trim((string) $cell),
                $row
            ), $rows),
            fn (array $row) => implode('', $row) !== ''
        ));

        if ($rows === []) {
            return [
                'schema' => SchemaFactory::emptySchema('Imported form'),
                'issues' => [['block' => '(sheet)', 'reason' => 'The first worksheet is empty.']],
            ];
        }

        $headerMap = $this->matchStructuredHeader($rows[0]);

        $result = $headerMap !== null
            ? $this->parseStructured($rows, $headerMap)
            : $this->parseHeaderRow($rows);

        $title = trim($sheet->getTitle());
        if ($title !== '' && ! in_array(strtolower($title), ['sheet1', 'sheet', 'worksheet'], true)) {
            $result['schema']['title'] = $title;
        }

        return $result;
    }

    /** @return array<string, int>|null column index per recognised header */
    private function matchStructuredHeader(array $headerRow): ?array
    {
        $map = [];
        foreach ($headerRow as $index => $cell) {
            $normalized = strtolower(trim($cell));
            if ($normalized === '') {
                continue;
            }
            if (in_array($normalized, self::LABEL_HEADERS, true)) {
                $map['label'] = $index;
            }
            foreach (['type', 'key', 'required', 'options', 'placeholder', 'help', 'section', 'min', 'max'] as $known) {
                if ($normalized === $known || $normalized === "field $known") {
                    $map[$known] = $index;
                }
            }
        }

        // A structured sheet needs at minimum a label column AND a type
        // column — otherwise we treat row 1 as plain field names.
        return isset($map['label'], $map['type']) ? $map : null;
    }

    /** @return array{schema: array, issues: array} */
    private function parseStructured(array $rows, array $map): array
    {
        $sections = [];
        $currentTitle = 'Imported fields';
        $currentFields = [];
        $issues = [];

        foreach (array_slice($rows, 1) as $rowIndex => $row) {
            $label = $row[$map['label']] ?? '';
            if ($label === '') {
                $issues[] = [
                    'block' => 'Row '.($rowIndex + 2),
                    'reason' => 'Empty label cell — row skipped.',
                ];

                continue;
            }

            $sectionName = isset($map['section']) ? ($row[$map['section']] ?? '') : '';
            if ($sectionName !== '' && $sectionName !== $currentTitle) {
                if ($currentFields !== []) {
                    $sections[] = SchemaFactory::section($currentTitle, ['fields' => $currentFields]);
                    $currentFields = [];
                }
                $currentTitle = $sectionName;
            }

            $rawType = $row[$map['type']] ?? '';
            $explicit = $rawType !== '' ? $this->inferencer->fromExplicit($rawType) : null;
            $inference = $this->inferencer->infer($label);
            $type = $explicit ?? $inference['type'];
            $confidence = $explicit !== null ? 'high' : $inference['confidence'];

            if ($rawType !== '' && $explicit === null) {
                $issues[] = [
                    'block' => 'Row '.($rowIndex + 2).": type \"$rawType\"",
                    'reason' => 'Unknown type — inferred "'.$type->value.'" from the label instead.',
                ];
                $confidence = 'low';
            }

            $field = SchemaFactory::field($type, [
                'label' => $label,
                'key' => isset($map['key']) && ($row[$map['key']] ?? '') !== ''
                    ? Str::slug($row[$map['key']], '_')
                    : Str::slug(Str::limit($label, 60, ''), '_'),
                'required' => isset($map['required'])
                    && in_array(strtolower($row[$map['required']] ?? ''), ['yes', 'y', 'true', '1', 'required'], true),
                'placeholder' => isset($map['placeholder']) ? ($row[$map['placeholder']] ?: null) : null,
                'description' => isset($map['help']) ? ($row[$map['help']] ?: null) : null,
            ]);

            if ($type->hasOptions()) {
                $rawOptions = isset($map['options']) ? ($row[$map['options']] ?? '') : '';
                $optionList = array_values(array_filter(array_map('trim', preg_split('/[|;,]/', $rawOptions))));
                if ($optionList === []) {
                    $issues[] = [
                        'block' => 'Row '.($rowIndex + 2).": \"$label\"",
                        'reason' => 'Choice field without options — placeholder option added.',
                    ];
                    $optionList = ['Option 1'];
                    $confidence = 'low';
                }
                $field['options'] = array_map(fn (string $option) => [
                    'label' => $option,
                    'value' => Str::slug(Str::limit($option, 50, ''), '_'),
                ], $optionList);
            }

            foreach (['min', 'max'] as $bound) {
                if (isset($map[$bound]) && is_numeric($row[$map[$bound]] ?? '')) {
                    $field['validation'][$bound] = $row[$map[$bound]] + 0;
                }
            }
            foreach ($inference['validation'] as $rule => $value) {
                $field['validation'][$rule] ??= $value;
            }
            $field['meta']['import_confidence'] = $confidence;

            $currentFields[] = $field;
        }

        if ($currentFields !== []) {
            $sections[] = SchemaFactory::section($currentTitle, ['fields' => $currentFields]);
        }

        $schema = SchemaFactory::emptySchema('Imported form');
        if ($sections !== []) {
            $schema['sections'] = $sections;
        } else {
            $issues[] = ['block' => '(sheet)', 'reason' => 'No usable rows below the header.'];
        }

        return ['schema' => $schema, 'issues' => $issues];
    }

    /** @return array{schema: array, issues: array} */
    private function parseHeaderRow(array $rows): array
    {
        $issues = [];
        $fields = [];
        $samples = $rows[1] ?? [];

        foreach ($rows[0] as $index => $label) {
            if ($label === '') {
                continue;
            }
            if (mb_strlen($label) > 150) {
                $issues[] = [
                    'block' => mb_substr($label, 0, 160),
                    'reason' => 'Header cell too long to be a field label.',
                ];

                continue;
            }

            $inference = $this->inferencer->infer($label, $samples[$index] ?? null);
            $field = SchemaFactory::field($inference['type'], [
                'label' => $label,
                'key' => Str::slug(Str::limit($label, 60, ''), '_'),
            ]);
            foreach ($inference['validation'] as $rule => $value) {
                $field['validation'][$rule] = $value;
            }
            $field['meta']['import_confidence'] = $inference['confidence'];
            $fields[] = $field;
        }

        $schema = SchemaFactory::emptySchema('Imported form');
        if ($fields !== []) {
            $schema['sections'] = [SchemaFactory::section('Imported fields', ['fields' => $fields])];
        } else {
            $issues[] = ['block' => '(sheet)', 'reason' => 'Row 1 contains no usable field names.'];
        }

        return ['schema' => $schema, 'issues' => $issues];
    }
}
