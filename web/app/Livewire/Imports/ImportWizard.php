<?php

declare(strict_types=1);

namespace App\Livewire\Imports;

use App\Enums\FieldType;
use App\Enums\ImportType;
use App\Enums\TaskStatus;
use App\Enums\VersionSource;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Services\FormService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Three-step wizard: upload -> queued parse -> preview & mapping ->
 * commit. Nothing becomes a form until the user has reviewed (and
 * possibly corrected) every detected field.
 */
#[Layout('layouts.app')]
class ImportWizard extends Component
{
    use WithFileUploads;

    public $upload = null;

    public ?string $importUuid = null;

    public ?string $error = null;

    /**
     * Editable mapping shown on the preview screen, flattened:
     * [{section, label, key, type, required, include, options_text, confidence}]
     *
     * @var list<array<string, mixed>>
     */
    public array $mapping = [];

    public string $formTitle = '';

    public function updatedUpload(): void
    {
        $this->error = null;
        $this->validate([
            'upload' => ['required', 'file', 'mimes:docx,xlsx', 'max:'.config('formforge.imports.max_size_kb')],
        ]);

        $extension = strtolower($this->upload->getClientOriginalExtension());
        $path = $this->upload->store('imports/'.auth()->id(), 'local');

        $import = Import::create([
            'user_id' => auth()->id(),
            'type' => $extension === 'docx' ? ImportType::Word : ImportType::Excel,
            'status' => TaskStatus::Queued,
            'original_filename' => $this->upload->getClientOriginalName(),
            'stored_path' => $path,
            'size_bytes' => $this->upload->getSize(),
        ]);

        ProcessImportJob::dispatch($import);
        $this->importUuid = $import->uuid;
        $this->mapping = [];
    }

    /** wire:poll target while parsing runs. */
    public function checkImport(): void
    {
        $import = $this->currentImport();
        if ($import === null) {
            return;
        }

        if ($import->status === TaskStatus::Failed) {
            $this->error = $import->error ?? 'Import failed.';
            $this->importUuid = null;

            return;
        }

        if ($import->status === TaskStatus::PreviewReady && $this->mapping === []) {
            $this->buildMapping($import);
        }
    }

    private function buildMapping(Import $import): void
    {
        $schema = $import->parsed_schema ?? [];
        $this->formTitle = $schema['title'] ?? 'Imported form';

        $mapping = [];
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $mapping[] = [
                    'section' => $section['title'] ?? 'Imported fields',
                    'label' => $field['label'] ?? 'Untitled',
                    'key' => $field['key'] ?? '',
                    'type' => $field['type'] ?? 'text',
                    'required' => (bool) ($field['required'] ?? false),
                    'include' => true,
                    'options_text' => implode(' | ', array_map(
                        fn (array $option) => $option['label'],
                        $field['options'] ?? []
                    )),
                    'confidence' => $field['meta']['import_confidence'] ?? 'high',
                    'placeholder' => $field['placeholder'] ?? null,
                    'description' => $field['description'] ?? null,
                    'validation' => $field['validation'] ?? [],
                ];
            }
        }
        $this->mapping = $mapping;
    }

    /** Rebuild a schema from the user-reviewed mapping rows. */
    private function buildSchemaFromMapping(): array
    {
        $sections = [];
        $order = [];

        foreach ($this->mapping as $row) {
            if (! ($row['include'] ?? true)) {
                continue;
            }

            $sectionTitle = $row['section'] ?: 'Imported fields';
            if (! isset($sections[$sectionTitle])) {
                $sections[$sectionTitle] = \App\Schema\SchemaFactory::section($sectionTitle);
                $order[] = $sectionTitle;
            }

            $type = FieldType::tryFrom($row['type']) ?? FieldType::Text;
            $field = \App\Schema\SchemaFactory::field($type, [
                'label' => $row['label'],
                'key' => $row['key'],
                'required' => (bool) $row['required'],
                'placeholder' => $row['placeholder'] ?? null,
                'description' => $row['description'] ?? null,
            ]);

            foreach (($row['validation'] ?? []) as $rule => $value) {
                if (array_key_exists($rule, $field['validation'])) {
                    $field['validation'][$rule] = $value;
                }
            }

            if ($type->hasOptions()) {
                $optionList = array_values(array_filter(array_map(
                    'trim',
                    preg_split('/[|;,]/', (string) ($row['options_text'] ?? ''))
                )));
                $field['options'] = array_map(fn (string $option) => [
                    'label' => $option,
                    'value' => \Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit($option, 50, ''), '_'),
                ], $optionList !== [] ? $optionList : ['Option 1']);
            }

            $sections[$sectionTitle]['fields'][] = $field;
        }

        $schema = \App\Schema\SchemaFactory::emptySchema($this->formTitle ?: 'Imported form');
        if ($order !== []) {
            $schema['sections'] = array_map(fn (string $title) => $sections[$title], $order);
        }

        return $schema;
    }

    /**
     * The "Create form" commit step. NB: deliberately NOT named commit()
     * — Livewire's JS $wire has an internal commit (request flush) that
     * shadows same-named actions, so wire:click="commit" never reaches
     * the server.
     */
    public function commitImport(FormService $formService): void
    {
        $import = $this->currentImport();
        if ($import === null || $import->status !== TaskStatus::PreviewReady) {
            return;
        }

        $schema = $this->buildSchemaFromMapping();

        try {
            $form = $formService->createFormFromSchema(
                auth()->user(),
                $schema,
                VersionSource::Import,
                label: 'Imported from '.$import->original_filename,
                lenient: true,
            );
        } catch (\App\Exceptions\InvalidSchemaException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $import->update(['status' => TaskStatus::Committed, 'form_id' => $form->id]);

        $this->redirectRoute('forms.builder', $form, navigate: true);
    }

    public function currentImport(): ?Import
    {
        return $this->importUuid === null
            ? null
            : Import::query()
                ->where('uuid', $this->importUuid)
                ->where('user_id', auth()->id())
                ->first();
    }

    public function startOver(): void
    {
        $this->reset(['upload', 'importUuid', 'error', 'mapping', 'formTitle']);
    }

    public function render()
    {
        return view('livewire.imports.import-wizard', [
            'import' => $this->currentImport(),
            'fieldTypes' => FieldType::cases(),
        ]);
    }
}
