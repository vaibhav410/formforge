<?php

declare(strict_types=1);

namespace App\Livewire\Builder;

use App\Enums\FieldType;
use App\Enums\VersionSource;
use App\Exceptions\InvalidSchemaException;
use App\Models\Form;
use App\Schema\SchemaFactory;
use App\Services\FormService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The form builder canvas. Holds the working draft schema and funnels
 * every mutation — palette clicks, drag/drop, inline edits, the JSON
 * editor, undo/redo snapshots — through one persist() path backed by
 * FormService, so the sanitize → validate → save guarantee applies to
 * the builder exactly as it does to AI output and imports.
 */
#[Layout('layouts.app')]
class FormBuilder extends Component
{
    #[Locked]
    public Form $form;

    public array $schema = [];

    public ?string $selectedId = null;

    public string $saveState = 'saved'; // saved|saving|error

    public ?string $savedAt = null;

    /** @var list<array{path: string, message: string}> */
    public array $schemaErrors = [];

    public bool $showJson = false;

    public string $jsonText = '';

    public ?string $jsonError = null;

    public bool $showAiPanel = false;

    public string $aiPrompt = '';

    public ?string $aiTaskUuid = null;

    public ?string $aiError = null;

    public function mount(Form $form): void
    {
        $this->authorize('update', $form);
        $this->form = $form;

        $draft = $form->latestDraftVersion() ?? $form->latestVersion();

        if ($draft === null) {
            $this->schema = SchemaFactory::emptySchema($form->title);
            $this->persist(recordHistory: false);
        } else {
            $this->schema = $draft->schema_json;
        }

        $this->syncJsonText();
        $this->savedAt = now()->format('H:i');
    }

    // ── Field mutations ──────────────────────────────────────────

    public function addField(string $type, string $sectionId, ?int $index = null): void
    {
        $fieldType = FieldType::tryFrom($type);
        if ($fieldType === null) {
            return;
        }

        $si = $this->sectionIndex($sectionId);
        if ($si === null) {
            return;
        }

        $field = SchemaFactory::field($fieldType);
        $field['key'] = $this->uniqueKey(Str::slug($fieldType->label(), '_'));
        $field['label'] = $fieldType->label();

        $fields = $this->schema['sections'][$si]['fields'];
        $index = $index === null ? count($fields) : max(0, min($index, count($fields)));
        array_splice($fields, $index, 0, [$field]);
        $this->schema['sections'][$si]['fields'] = $fields;

        $this->selectedId = $field['id'];
        $this->persist();
    }

    public function duplicateField(string $fieldId): void
    {
        $pos = $this->fieldPosition($fieldId);
        if ($pos === null) {
            return;
        }
        [$si, $fi] = $pos;

        $copy = $this->schema['sections'][$si]['fields'][$fi];
        $copy['id'] = \App\Schema\SchemaSanitizer::newId('fld');
        $copy['key'] = $this->uniqueKey($copy['key']);
        $copy['label'] .= ' (copy)';

        array_splice($this->schema['sections'][$si]['fields'], $fi + 1, 0, [$copy]);
        $this->selectedId = $copy['id'];
        $this->persist();
    }

    public function removeField(string $fieldId): void
    {
        $pos = $this->fieldPosition($fieldId);
        if ($pos === null) {
            return;
        }
        [$si, $fi] = $pos;

        array_splice($this->schema['sections'][$si]['fields'], $fi, 1);
        if ($this->selectedId === $fieldId) {
            $this->selectedId = null;
        }
        $this->persist();
    }

    public function moveField(string $fieldId, string $toSectionId, int $toIndex): void
    {
        $pos = $this->fieldPosition($fieldId);
        $toSi = $this->sectionIndex($toSectionId);
        if ($pos === null || $toSi === null) {
            return;
        }
        [$si, $fi] = $pos;

        $field = $this->schema['sections'][$si]['fields'][$fi];
        array_splice($this->schema['sections'][$si]['fields'], $fi, 1);

        $target = $this->schema['sections'][$toSi]['fields'];
        $toIndex = max(0, min($toIndex, count($target)));
        array_splice($target, $toIndex, 0, [$field]);
        $this->schema['sections'][$toSi]['fields'] = $target;

        $this->persist();
    }

    // ── Section mutations ────────────────────────────────────────

    public function addSection(): void
    {
        $this->schema['sections'][] = SchemaFactory::section(
            'Section '.(count($this->schema['sections']) + 1)
        );
        $this->persist();
    }

    public function removeSection(string $sectionId): void
    {
        if (count($this->schema['sections']) <= 1) {
            return; // a form always keeps one section
        }
        $si = $this->sectionIndex($sectionId);
        if ($si === null) {
            return;
        }
        array_splice($this->schema['sections'], $si, 1);
        $this->persist();
    }

    public function moveSection(string $sectionId, int $toIndex): void
    {
        $si = $this->sectionIndex($sectionId);
        if ($si === null) {
            return;
        }
        $section = $this->schema['sections'][$si];
        array_splice($this->schema['sections'], $si, 1);
        $toIndex = max(0, min($toIndex, count($this->schema['sections'])));
        array_splice($this->schema['sections'], $toIndex, 0, [$section]);
        $this->persist();
    }

    // ── Options & logic on the selected field ────────────────────

    public function addOption(string $fieldId): void
    {
        $pos = $this->fieldPosition($fieldId);
        if ($pos === null) {
            return;
        }
        [$si, $fi] = $pos;
        $options = $this->schema['sections'][$si]['fields'][$fi]['options'] ?? [];
        $n = count($options) + 1;
        $options[] = ['label' => "Option $n", 'value' => "option_$n"];
        $this->schema['sections'][$si]['fields'][$fi]['options'] = $options;
        $this->persist();
    }

    public function removeOption(string $fieldId, int $index): void
    {
        $pos = $this->fieldPosition($fieldId);
        if ($pos === null) {
            return;
        }
        [$si, $fi] = $pos;
        $options = $this->schema['sections'][$si]['fields'][$fi]['options'] ?? [];
        if (count($options) <= 1) {
            return; // validator requires at least one option
        }
        array_splice($options, $index, 1);
        $this->schema['sections'][$si]['fields'][$fi]['options'] = $options;
        $this->persist();
    }

    public function toggleLogic(string $fieldId): void
    {
        $pos = $this->fieldPosition($fieldId);
        if ($pos === null) {
            return;
        }
        [$si, $fi] = $pos;
        $field = &$this->schema['sections'][$si]['fields'][$fi];

        if (($field['logic'] ?? null) === null) {
            $firstOtherKey = collect($this->allFields())
                ->pluck('key')
                ->first(fn ($k) => $k !== $field['key']);
            if ($firstOtherKey === null) {
                return; // nothing to depend on
            }
            $field['logic'] = [
                'action' => 'show',
                'match' => 'all',
                'conditions' => [['field' => $firstOtherKey, 'operator' => 'equals', 'value' => '']],
            ];
        } else {
            $field['logic'] = null;
        }
        $this->persist();
    }

    public function addCondition(string $fieldId): void
    {
        $pos = $this->fieldPosition($fieldId);
        if ($pos === null) {
            return;
        }
        [$si, $fi] = $pos;
        $field = &$this->schema['sections'][$si]['fields'][$fi];
        if (($field['logic'] ?? null) === null) {
            return;
        }
        $firstOtherKey = collect($this->allFields())
            ->pluck('key')
            ->first(fn ($k) => $k !== $field['key']);
        $field['logic']['conditions'][] = ['field' => $firstOtherKey, 'operator' => 'equals', 'value' => ''];
        $this->persist();
    }

    public function removeCondition(string $fieldId, int $index): void
    {
        $pos = $this->fieldPosition($fieldId);
        if ($pos === null) {
            return;
        }
        [$si, $fi] = $pos;
        $field = &$this->schema['sections'][$si]['fields'][$fi];
        $conditions = $field['logic']['conditions'] ?? [];
        array_splice($conditions, $index, 1);
        if ($conditions === []) {
            $field['logic'] = null;
        } else {
            $field['logic']['conditions'] = $conditions;
        }
        $this->persist();
    }

    // ── Selection ────────────────────────────────────────────────

    public function select(?string $id): void
    {
        $this->selectedId = $id;
    }

    // ── JSON editor & snapshots ──────────────────────────────────

    public function applyJson(): void
    {
        $decoded = json_decode($this->jsonText, true);
        if (! is_array($decoded)) {
            $this->jsonError = 'Not valid JSON: '.json_last_error_msg();

            return;
        }
        $this->jsonError = null;

        // The canvas must never render an unsanitized schema — keep the
        // last valid one when the pasted JSON fails validation. The
        // editor keeps the user's text so they can fix it in place.
        $lastValid = $this->schema;
        $this->schema = $decoded;
        $this->persist();

        if ($this->saveState === 'error') {
            $this->schema = $lastValid;
        }
    }

    /** Undo/redo entry point — snapshots come from the Alpine history store. */
    public function applySnapshot(array $schema): void
    {
        $this->schema = $schema;
        $this->persist(recordHistory: false);
    }

    // ── AI edit / translate ──────────────────────────────────────

    public function queueAiChange(string $mode): void
    {
        if (! in_array($mode, ['edit', 'translate'], true) || $this->aiTaskUuid !== null) {
            return;
        }
        $this->validate(['aiPrompt' => ['required', 'string', 'min:3', 'max:2000']]);
        $this->aiError = null;

        $key = 'ai-generation:'.auth()->id();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, (int) config('formforge.rate_limits.ai_generations_per_hour'))) {
            $this->aiError = 'AI limit reached — try again in a while.';

            return;
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 3600);

        $task = \App\Models\AiTask::create([
            'user_id' => auth()->id(),
            'form_id' => $this->form->id,
            'type' => $mode === 'edit' ? \App\Enums\AiTaskType::Edit : \App\Enums\AiTaskType::Translate,
            'status' => \App\Enums\TaskStatus::Queued,
            'prompt' => $this->aiPrompt,
            'input_schema' => $this->schema,
        ]);

        \App\Jobs\RunAiTaskJob::dispatch($task);
        $this->aiTaskUuid = $task->uuid;
    }

    /** wire:poll target while an AI task runs. */
    public function checkAiTask(): void
    {
        if ($this->aiTaskUuid === null) {
            return;
        }
        $task = \App\Models\AiTask::query()->where('uuid', $this->aiTaskUuid)->first();
        if ($task === null || ! $task->status->isTerminal()) {
            return;
        }

        $this->aiTaskUuid = null;

        if ($task->status === \App\Enums\TaskStatus::Failed) {
            $this->aiError = $task->error ?? 'The AI request failed.';

            return;
        }

        // The job saved the result as the new draft — adopt it.
        $draft = $this->form->latestDraftVersion() ?? $this->form->latestVersion();
        if ($draft !== null) {
            $this->schema = $draft->schema_json;
            $this->syncJsonText();
            $this->aiPrompt = '';
            $this->form->refresh();
            $this->savedAt = now()->format('H:i');
            $this->dispatch('schema-committed', schema: $this->schema);
        }
    }

    // ── Persistence ──────────────────────────────────────────────

    /**
     * Livewire hook: fires for wire:model writes into the schema array
     * (labels, placeholders, validation numbers, option rows…).
     */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'schema.')) {
            $this->persist();
        }
    }

    public function persist(bool $recordHistory = true): void
    {
        $service = app(FormService::class);

        try {
            $draft = $service->saveDraftSchema($this->form, $this->schema);
            // Adopt the sanitized canonical form (generated ids, deduped keys).
            $this->schema = $draft->schema_json;
            $this->schemaErrors = [];
            $this->saveState = 'saved';
            $this->savedAt = now()->format('H:i');
            $this->form->refresh();
        } catch (InvalidSchemaException $e) {
            $this->schemaErrors = $e->errors;
            $this->saveState = 'error';
        }

        $this->syncJsonText();

        if ($recordHistory && $this->saveState === 'saved') {
            $this->dispatch('schema-committed', schema: $this->schema);
        }
    }

    public function publish(): void
    {
        $this->authorize('publish', $this->form);
        // Publish the latest saved draft; refuse while the schema is broken.
        if ($this->schemaErrors !== []) {
            return;
        }
        app(FormService::class)->publish($this->form);
        $this->form->refresh();
        $this->dispatch('published');
    }

    public function unpublish(): void
    {
        $this->authorize('publish', $this->form);
        app(FormService::class)->unpublish($this->form);
        $this->form->refresh();
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function sectionIndex(string $sectionId): ?int
    {
        foreach ($this->schema['sections'] as $i => $section) {
            if (($section['id'] ?? null) === $sectionId) {
                return $i;
            }
        }

        return null;
    }

    /** @return array{0: int, 1: int}|null [sectionIndex, fieldIndex] */
    private function fieldPosition(string $fieldId): ?array
    {
        foreach ($this->schema['sections'] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                if (($field['id'] ?? null) === $fieldId) {
                    return [$si, $fi];
                }
            }
        }

        return null;
    }

    /** @return list<array> */
    private function allFields(): array
    {
        $fields = [];
        foreach ($this->schema['sections'] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function uniqueKey(string $base): string
    {
        $base = $base !== '' ? $base : 'field';
        $keys = array_column($this->allFields(), 'key');
        $candidate = $base;
        $i = 2;
        while (in_array($candidate, $keys, true)) {
            $candidate = $base.'_'.$i;
            $i++;
        }

        return $candidate;
    }

    /** @return array{0: int, 1: int}|null */
    public function selectedPosition(): ?array
    {
        return $this->selectedId === null ? null : $this->fieldPosition($this->selectedId);
    }

    private function syncJsonText(): void
    {
        $this->jsonText = json_encode(
            $this->schema,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public function render()
    {
        return view('livewire.builder.form-builder', [
            'palette' => FieldType::cases(),
            'selected' => $this->selectedPosition(),
            'fieldKeys' => array_column($this->allFields(), 'key'),
        ]);
    }
}
