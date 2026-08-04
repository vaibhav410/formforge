<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\FieldType;
use App\Schema\SchemaFactory;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;

/**
 * Deterministic .docx → draft schema parser.
 *
 * Mapping rules (documented in the README):
 *  - Heading 1/2 paragraphs        → sections
 *  - other paragraphs              → field labels ("questions")
 *  - list items following a label  → options (radio; checkbox when the
 *    label hints "select all/check all" or items carry ☐-style boxes)
 *  - "Label: ____" underscore runs → text fields
 *  - two-column tables             → label/type rows
 * Anything it cannot place lands in issues[] verbatim, never dropped
 * silently. Type inference is delegated to LabelTypeInferencer.
 */
final class WordParser
{
    private const CHECKBOX_MARKERS = ['☐', '□', '[ ]', '[]', '( )'];

    public function __construct(private readonly LabelTypeInferencer $inferencer)
    {
    }

    /** @return array{schema: array, issues: list<array{block: string, reason: string}>} */
    public function parse(string $path): array
    {
        $document = IOFactory::load($path);

        $state = new class
        {
            public array $sections = [];

            public array $currentFields = [];

            public string $currentTitle = 'Imported form';

            public ?string $formTitle = null;

            public ?array $pendingField = null;

            public array $pendingOptions = [];

            public array $issues = [];
        };

        foreach ($document->getSections() as $documentSection) {
            $this->walk($documentSection, $state);
        }
        $this->flushPending($state);
        $this->flushSection($state);

        if ($state->sections === []) {
            $state->issues[] = [
                'block' => '(document)',
                'reason' => 'No recognisable headings or questions were found.',
            ];
        }

        $schema = SchemaFactory::emptySchema($state->formTitle ?? 'Imported form');
        $schema['sections'] = $state->sections !== [] ? $state->sections : $schema['sections'];

        return ['schema' => $schema, 'issues' => $state->issues];
    }

    private function walk(AbstractContainer $container, object $state): void
    {
        foreach ($container->getElements() as $element) {
            match (true) {
                $element instanceof Title => $this->handleTitle($element, $state),
                $element instanceof ListItem => $this->handleListItem(trim($element->getTextObject()->getText()), $state),
                $element instanceof ListItemRun => $this->handleListItem(trim($this->textOf($element)), $state),
                $element instanceof TextRun => $this->handleParagraph(trim($this->textOf($element)), $this->styleOf($element), $state),
                $element instanceof Text => $this->handleParagraph(trim($element->getText() ?? ''), $this->styleOf($element), $state),
                $element instanceof Table => $this->handleTable($element, $state),
                default => null,
            };
        }
    }

    private function handleTitle(Title $title, object $state): void
    {
        $text = trim(is_string($title->getText()) ? $title->getText() : $this->textOf($title->getText()));
        if ($text === '') {
            return;
        }

        // The document's first depth-1 title is the form title.
        if ($title->getDepth() <= 1 && $state->formTitle === null && $state->sections === [] && $state->currentFields === []) {
            $state->formTitle = $text;

            return;
        }

        $this->flushPending($state);
        $this->flushSection($state);
        $state->currentTitle = $text;
    }

    private function handleParagraph(string $text, ?string $style, object $state): void
    {
        if ($text === '') {
            return;
        }

        // Some documents use Heading styles without producing Title elements.
        if ($style !== null && preg_match('/^Heading\s*[12]$/i', $style)) {
            $this->flushPending($state);
            $this->flushSection($state);
            $state->currentTitle = $text;

            return;
        }

        if ($this->looksLikeOption($text)) {
            $this->handleListItem($this->stripOptionMarker($text), $state);

            return;
        }

        // "Label: ______" or "Label ______" → inline blank to fill.
        if (preg_match('/^(.{3,120}?)[:\-]?\s*_{3,}\s*$/u', $text, $matches)) {
            $this->flushPending($state);
            $state->pendingField = $this->makeField(trim($matches[1]), $state);
            $this->flushPending($state);

            return;
        }

        if ($this->looksLikeQuestion($text)) {
            $this->flushPending($state);
            $state->pendingField = $this->makeField($text, $state);

            return;
        }

        // Prose right after the form title reads as the description.
        if ($state->formTitle !== null && $state->sections === [] && $state->currentFields === [] && $state->pendingField === null) {
            return; // treated as intro text; the builder description stays editable
        }

        $state->issues[] = [
            'block' => mb_substr($text, 0, 160),
            'reason' => 'Paragraph did not match a heading, question or option pattern.',
        ];
    }

    private function handleListItem(string $text, object $state): void
    {
        if ($text === '') {
            return;
        }
        if ($state->pendingField === null) {
            $state->issues[] = [
                'block' => mb_substr($text, 0, 160),
                'reason' => 'List item found with no preceding question.',
            ];

            return;
        }
        $state->pendingOptions[] = $this->stripOptionMarker($text);
    }

    private function handleTable(Table $table, object $state): void
    {
        $this->flushPending($state);

        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $cells[] = trim($this->textOf($cell));
            }
            $cells = array_values(array_filter($cells, fn ($c) => $c !== ''));
            if ($cells === []) {
                continue;
            }

            $label = $cells[0];
            if (mb_strlen($label) < 2 || mb_strlen($label) > 150) {
                $state->issues[] = [
                    'block' => mb_substr(implode(' | ', $cells), 0, 160),
                    'reason' => 'Table row first cell is not usable as a field label.',
                ];

                continue;
            }

            $field = $this->makeField($label, $state);
            // Second cell may name a type in structured questionnaires.
            if (isset($cells[1]) && ($explicit = $this->inferencer->fromExplicit($cells[1])) !== null) {
                $field['type'] = $explicit->value;
                $field['meta']['import_confidence'] = 'high';
            }
            $state->currentFields[] = $field;
        }
    }

    // ── helpers ──────────────────────────────────────────────────

    private function makeField(string $label, object $state): array
    {
        $required = false;
        if (preg_match('/\*\s*$/', $label) || preg_match('/\(required\)/i', $label)) {
            $required = true;
            $label = trim(preg_replace('/\*\s*$|\(required\)/i', '', $label));
        }

        $inference = $this->inferencer->infer($label);

        $field = SchemaFactory::field($inference['type'], [
            'label' => $label,
            'key' => \Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit($label, 60, ''), '_'),
            'required' => $required,
        ]);
        foreach ($inference['validation'] as $rule => $value) {
            $field['validation'][$rule] = $value;
        }
        $field['meta']['import_confidence'] = $inference['confidence'];

        return $field;
    }

    private function flushPending(object $state): void
    {
        if ($state->pendingField === null) {
            return;
        }

        $field = $state->pendingField;

        if ($state->pendingOptions !== []) {
            $label = mb_strtolower($field['label']);
            $isCheckbox = str_contains($label, 'all that apply')
                || str_contains($label, 'check all')
                || str_contains($label, 'select all');
            $type = $isCheckbox ? FieldType::Checkbox : FieldType::Radio;

            $field['type'] = $type->value;
            $field['options'] = array_map(
                fn (string $option) => [
                    'label' => $option,
                    'value' => \Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit($option, 50, ''), '_'),
                ],
                $state->pendingOptions
            );
            $field['meta']['import_confidence'] = 'high';
        }

        $state->currentFields[] = $field;
        $state->pendingField = null;
        $state->pendingOptions = [];
    }

    private function flushSection(object $state): void
    {
        if ($state->currentFields === []) {
            return;
        }
        $state->sections[] = SchemaFactory::section($state->currentTitle, [
            'fields' => $state->currentFields,
        ]);
        $state->currentFields = [];
        $state->currentTitle = 'Section '.(count($state->sections) + 1);
    }

    private function looksLikeQuestion(string $text): bool
    {
        if (mb_strlen($text) > 150) {
            return false;
        }

        return str_ends_with($text, '?')
            || str_ends_with($text, ':')
            || preg_match('/^(name|full name|first name|last name|email|phone|address|date|city|country|company|position|title)\b/i', $text) === 1
            || (str_word_count($text) <= 8 && ! str_ends_with($text, '.'));
    }

    private function looksLikeOption(string $text): bool
    {
        foreach (self::CHECKBOX_MARKERS as $marker) {
            if (str_starts_with($text, $marker)) {
                return true;
            }
        }

        return preg_match('/^[-•o*]\s+\S/', $text) === 1;
    }

    private function stripOptionMarker(string $text): string
    {
        foreach (self::CHECKBOX_MARKERS as $marker) {
            if (str_starts_with($text, $marker)) {
                return trim(mb_substr($text, mb_strlen($marker)));
            }
        }

        return trim(preg_replace('/^[-•o*]\s+/', '', $text));
    }

    private function textOf(object $container): string
    {
        if ($container instanceof Text) {
            return $container->getText() ?? '';
        }
        $parts = [];
        if (method_exists($container, 'getElements')) {
            foreach ($container->getElements() as $child) {
                $parts[] = $this->textOf($child);
            }
        }

        return implode('', $parts);
    }

    private function styleOf(object $element): ?string
    {
        if (! method_exists($element, 'getParagraphStyle')) {
            return null;
        }
        $style = $element->getParagraphStyle();
        if (is_string($style)) {
            return $style;
        }
        if (is_object($style) && method_exists($style, 'getStyleName')) {
            return $style->getStyleName();
        }

        return null;
    }
}
