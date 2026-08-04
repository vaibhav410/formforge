<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FieldType;
use App\Events\SubmissionCreated;
use App\Models\Form;
use App\Models\Submission;
use App\Schema\ConditionEvaluator;
use App\Schema\FormSchema;
use App\Schema\ValidationRuleCompiler;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Public submission pipeline: compile rules from the published schema,
 * validate, then store answers — all inside one transaction. The
 * browser's opinion of visibility or validity is never consulted.
 */
class SubmissionService
{
    public function __construct(
        private readonly ValidationRuleCompiler $compiler,
        private readonly ConditionEvaluator $conditions,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input  answers keyed by field key
     * @return \Illuminate\Validation\Validator
     */
    public function makeValidator(FormSchema $schema, array $input): \Illuminate\Validation\Validator
    {
        $compiled = $this->compiler->compile($schema, $input);

        return Validator::make($input, $compiled['rules'], [], $compiled['attributes']);
    }

    /**
     * @param  array<string, mixed>  $validated  validated answers (visible fields only)
     */
    public function store(Form $form, FormSchema $schema, array $validated, Request $request): Submission
    {
        $startedAt = $this->decodeRenderToken($request->input('_rt'));

        return DB::transaction(function () use ($form, $schema, $validated, $request, $startedAt) {
            $now = now();

            $submission = $form->submissions()->create([
                'form_version_id' => $form->published_version_id,
                'ip_hash' => hash('sha256', $request->ip().'|'.$now->toDateString()),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'referrer' => substr((string) $request->headers->get('referer'), 0, 512),
                'started_at' => $startedAt,
                'submitted_at' => $now,
                'duration_seconds' => $startedAt !== null ? max(0, $startedAt->diffInSeconds($now)) : null,
            ]);

            // Only visible answerable fields are stored — hidden-by-logic
            // values are discarded even if present in the payload.
            $visible = $this->conditions->visibleFieldKeys($schema, $validated);

            foreach ($schema->answerableFields() as $field) {
                $key = $field['key'];
                if (! in_array($key, $visible, true) || ! array_key_exists($key, $validated)) {
                    continue;
                }

                [$text, $json] = $this->normalizeAnswer($field, $validated[$key], $form);
                if ($text === null && $json === null) {
                    continue;
                }

                $submission->answers()->create([
                    'form_id' => $form->id,
                    'field_key' => $key,
                    'field_type' => $field['type'],
                    'value_text' => $text,
                    'value_json' => $json,
                ]);
            }

            $form->increment('submissions_count');

            SubmissionCreated::dispatch($submission);

            return $submission;
        });
    }

    /** @return array{0: ?string, 1: ?array} [value_text, value_json] */
    private function normalizeAnswer(array $field, mixed $value, Form $form): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [null, null];
        }

        $type = FieldType::from($field['type']);

        return match ($type) {
            FieldType::Checkbox => [null, array_map('strval', (array) $value)],
            FieldType::Address => [null, array_map(
                fn ($v) => $v === null ? null : (string) $v,
                (array) $value
            )],
            FieldType::File => [null, $this->storeFile($value, $form)],
            // Password answers are encrypted at rest; only the form owner
            // can read them back (decrypted in the submissions UI).
            FieldType::Password => [Crypt::encryptString((string) $value), null],
            default => [is_array($value) ? json_encode($value) : (string) $value, null],
        };
    }

    /** @return array{name: string, path: string, size_kb: int}|null */
    private function storeFile(mixed $file, Form $form): ?array
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $path = $file->store("submissions/{$form->id}", 'local');

        return [
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'size_kb' => (int) ceil($file->getSize() / 1024),
        ];
    }

    /** Issued at render time; used for duration + minimum-fill-time spam check. */
    public function makeRenderToken(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    public function decodeRenderToken(mixed $token): ?\Illuminate\Support\Carbon
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $timestamp = (int) Crypt::decryptString($token);

            return $timestamp > 0 ? \Illuminate\Support\Carbon::createFromTimestamp($timestamp) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
