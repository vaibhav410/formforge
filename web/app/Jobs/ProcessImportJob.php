<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportType;
use App\Enums\TaskStatus;
use App\Models\Import;
use App\Services\Ai\AiServiceClient;
use App\Services\Import\ExcelParser;
use App\Services\Import\WordParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Parses an uploaded document into a draft schema for the mapping
 * screen. Deterministic parsing always runs; the AI pass is optional,
 * scoped to low-confidence fields only, and its failure never fails
 * the import (the deterministic result stands).
 */
class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(private readonly Import $import)
    {
    }

    public function handle(
        WordParser $wordParser,
        ExcelParser $excelParser,
        AiServiceClient $ai,
    ): void {
        $import = $this->import;
        $import->update(['status' => TaskStatus::Processing]);

        try {
            $path = Storage::disk('local')->path($import->stored_path);

            $result = $import->type === ImportType::Word
                ? $wordParser->parse($path)
                : $excelParser->parse($path);

            $schema = $result['schema'];
            $issues = $result['issues'];
            $aiUsed = false;

            // Hybrid step: let the AI refine ONLY what parsing was unsure
            // about. Deterministic output survives any AI failure.
            $lowConfidenceLabels = $this->lowConfidenceLabels($schema);
            if ($lowConfidenceLabels !== [] && $ai->healthy()) {
                try {
                    $refined = $ai->edit(
                        'Without adding or removing any fields and without changing any keys or labels, '
                        .'infer better field types, options and validation rules for these ambiguous fields: '
                        .implode('; ', array_slice($lowConfidenceLabels, 0, 20))
                        .'. Leave every other field untouched.',
                        $schema,
                    );
                    if ($this->sameFieldCount($schema, $refined->schema)) {
                        $schema = $refined->schema;
                        $aiUsed = true;
                    }
                } catch (\Throwable $e) {
                    $issues[] = [
                        'block' => '(AI assist)',
                        'reason' => 'Type refinement skipped: '.substr($e->getMessage(), 0, 120),
                    ];
                }
            }

            $import->update([
                'status' => TaskStatus::PreviewReady,
                'parsed_schema' => $schema,
                'issues' => $issues,
                'ai_used' => $aiUsed,
            ]);
        } catch (\Throwable $e) {
            $import->update([
                'status' => TaskStatus::Failed,
                'error' => substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }
    }

    /** @return list<string> */
    private function lowConfidenceLabels(array $schema): array
    {
        $labels = [];
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (($field['meta']['import_confidence'] ?? 'high') === 'low') {
                    $labels[] = $field['label'];
                }
            }
        }

        return $labels;
    }

    private function sameFieldCount(array $before, array $after): bool
    {
        $count = fn (array $schema) => array_sum(array_map(
            fn (array $section) => count($section['fields'] ?? []),
            $schema['sections'] ?? []
        ));

        return $count($before) === $count($after);
    }
}
