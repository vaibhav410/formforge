<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\FieldType;

/**
 * Deterministic type inference shared by the Word and Excel parsers.
 * Returns the inferred type plus a confidence flag — low-confidence
 * fields are the only ones the AI pass is allowed to touch.
 */
final class LabelTypeInferencer
{
    /** @return array{type: FieldType, confidence: 'high'|'low', validation: array} */
    public function infer(string $label, ?string $sampleValue = null): array
    {
        $lower = mb_strtolower($label);

        // Strong keyword signals.
        foreach ([
            [FieldType::Email, ['email', 'e-mail']],
            [FieldType::Phone, ['phone', 'mobile', 'contact number', 'whatsapp', 'tel.']],
            [FieldType::Date, ['date', 'dob', 'birth', 'deadline', 'available from', 'start on']],
            [FieldType::Time, ['time', 'hour of']],
            [FieldType::File, ['upload', 'resume', 'cv', 'attach', 'certificate', 'photo', 'document', 'transcript']],
            [FieldType::Url, ['url', 'website', 'portfolio', 'linkedin', 'github', 'link']],
            [FieldType::Address, ['address']],
            [FieldType::Rating, ['rating', 'rate ', 'stars', 'scale of']],
            [FieldType::Number, ['number of', 'how many', 'age', 'years', 'salary', 'cgpa', 'gpa', 'percentage', 'marks', 'amount', 'quantity']],
            [FieldType::Signature, ['signature', 'sign here']],
            [FieldType::Color, ['colour preference', 'color preference']],
        ] as [$type, $keywords]) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $this->result($type, 'high');
                }
            }
        }

        // Sample-data signals (Excel header-row layout).
        if ($sampleValue !== null && $sampleValue !== '') {
            if (filter_var($sampleValue, FILTER_VALIDATE_EMAIL)) {
                return $this->result(FieldType::Email, 'high');
            }
            if (filter_var($sampleValue, FILTER_VALIDATE_URL)) {
                return $this->result(FieldType::Url, 'high');
            }
            if (is_numeric($sampleValue)) {
                return $this->result(FieldType::Number, 'high');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampleValue) || strtotime($sampleValue) !== false && preg_match('/\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}/', $sampleValue)) {
                return $this->result(FieldType::Date, 'high');
            }
            if (preg_match('/^\+?[0-9 ()\-]{7,20}$/', $sampleValue)) {
                return $this->result(FieldType::Phone, 'high');
            }
        }

        // Long-answer heuristics.
        if (preg_match('/describe|explain|tell us|why |comments|feedback|details|elaborate|motivation/', $lower)) {
            return $this->result(FieldType::Textarea, 'high');
        }

        // Yes/no style questions read as radio, but options are unknown —
        // flag low confidence so the AI pass (or the user) can refine.
        if (str_ends_with(trim($label), '?')) {
            return $this->result(FieldType::Text, 'low');
        }

        return $this->result(FieldType::Text, str_word_count($label) > 6 ? 'low' : 'high');
    }

    /** Map an explicit type cell from a structured sheet to a FieldType. */
    public function fromExplicit(string $raw): ?FieldType
    {
        $normalized = strtolower(trim($raw));
        $aliases = [
            'select' => FieldType::Dropdown,
            'multiselect' => FieldType::Checkbox,
            'multi-select' => FieldType::Checkbox,
            'choice' => FieldType::Radio,
            'single choice' => FieldType::Radio,
            'multiple choice' => FieldType::Checkbox,
            'boolean' => FieldType::Radio,
            'yes/no' => FieldType::Radio,
            'upload' => FieldType::File,
            'attachment' => FieldType::File,
            'paragraph' => FieldType::Textarea,
            'longtext' => FieldType::Textarea,
            'long text' => FieldType::Textarea,
            'string' => FieldType::Text,
            'integer' => FieldType::Number,
            'numeric' => FieldType::Number,
            'tel' => FieldType::Phone,
            'datetime' => FieldType::Date,
        ];

        return FieldType::tryFrom($normalized) ?? $aliases[$normalized] ?? null;
    }

    private function result(FieldType $type, string $confidence): array
    {
        $validation = match ($type) {
            FieldType::File => ['mimes' => ['pdf', 'doc', 'docx', 'png', 'jpg'], 'max_size_kb' => 5120],
            default => [],
        };

        return ['type' => $type, 'confidence' => $confidence, 'validation' => $validation];
    }
}
