<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FieldType;
use App\Enums\TaskStatus;
use App\Models\FormExport;
use App\Models\Submission;
use App\Schema\FormSchema;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Queued CSV export. Streams row-by-row over chunked queries so a form
 * with 100k submissions exports in constant memory. Columns follow the
 * current schema's field order; keys from older versions that no longer
 * exist are appended at the end so no data silently disappears.
 */
class ExportSubmissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(private readonly FormExport $export)
    {
    }

    public function handle(): void
    {
        $export = $this->export;
        $export->update(['status' => TaskStatus::Processing]);

        try {
            $form = $export->form;
            $schema = FormSchema::fromArray(
                ($form->publishedVersion ?? $form->latestVersion())?->schema_json ?? ['sections' => []]
            );

            // Schema-ordered columns first, then orphaned keys from old versions.
            $schemaFields = [];
            foreach ($schema->answerableFields() as $field) {
                $schemaFields[$field['key']] = $field;
            }
            $extraKeys = $form->submissions()
                ->join('submission_answers', 'submissions.id', '=', 'submission_answers.submission_id')
                ->whereNotIn('submission_answers.field_key', array_keys($schemaFields) ?: [''])
                ->distinct()
                ->pluck('submission_answers.field_key')
                ->all();

            $columns = [...array_keys($schemaFields), ...$extraKeys];

            $path = "exports/{$form->id}/{$export->uuid}.csv";
            Storage::disk('local')->makeDirectory("exports/{$form->id}");
            $handle = fopen(Storage::disk('local')->path($path), 'w');

            // UTF-8 BOM so Excel opens accents correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Submission ID', 'Submitted at', 'Duration (s)',
                ...array_map(
                    fn (string $key) => $schemaFields[$key]['label'] ?? $key,
                    $columns
                ),
            ]);

            $rows = 0;
            $form->submissions()
                ->with('answers')
                ->orderBy('id')
                ->chunk(500, function ($submissions) use ($handle, $columns, $schemaFields, &$rows) {
                    foreach ($submissions as $submission) {
                        fputcsv($handle, [
                            $submission->uuid,
                            $submission->submitted_at->toDateTimeString(),
                            $submission->duration_seconds,
                            ...array_map(
                                fn (string $key) => $this->cell($key, $submission),
                                $columns
                            ),
                        ]);
                        $rows++;
                    }
                });

            fclose($handle);

            $export->update([
                'status' => TaskStatus::Completed,
                'stored_path' => $path,
                'row_count' => $rows,
            ]);
        } catch (\Throwable $e) {
            $export->update([
                'status' => TaskStatus::Failed,
                'error' => substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }
    }

    private function cell(string $key, Submission $submission): string
    {
        $answer = $submission->answers->firstWhere('field_key', $key);
        if ($answer === null) {
            return '';
        }

        $value = $answer->value();
        $type = FieldType::tryFrom($answer->field_type);

        return match ($type) {
            FieldType::Checkbox => implode('; ', (array) $value),
            FieldType::Address => implode(', ', array_filter((array) $value)),
            FieldType::File => is_array($value) ? ($value['name'] ?? '') : '',
            // Never write secrets or blobs into a spreadsheet.
            FieldType::Password => '[encrypted]',
            FieldType::Signature => '[signature]',
            default => is_array($value) ? json_encode($value) : (string) $value,
        };
    }
}
