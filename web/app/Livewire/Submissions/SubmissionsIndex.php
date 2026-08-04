<?php

declare(strict_types=1);

namespace App\Livewire\Submissions;

use App\Enums\FieldType;
use App\Jobs\ExportSubmissionsJob;
use App\Models\Form;
use App\Models\Submission;
use App\Schema\FormSchema;
use Illuminate\Support\Facades\Crypt;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SubmissionsIndex extends Component
{
    use WithPagination;

    #[Locked]
    public Form $form;

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $expandedId = null;

    public function mount(Form $form): void
    {
        $this->authorize('viewSubmissions', $form);
        $this->form = $form;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleExpand(int $submissionId): void
    {
        $this->expandedId = $this->expandedId === $submissionId ? null : $submissionId;
    }

    public function deleteSubmission(int $submissionId): void
    {
        $submission = $this->form->submissions()->findOrFail($submissionId);
        $submission->delete();
        $this->form->decrement('submissions_count');
        $this->expandedId = null;
    }

    #[\Livewire\Attributes\On('request-export')]
    public function requestExport(): void
    {
        $export = \App\Models\FormExport::create([
            'user_id' => auth()->id(),
            'form_id' => $this->form->id,
        ]);

        ExportSubmissionsJob::dispatch($export);
    }

    /** Answer rendered for the owner (passwords decrypted here only). */
    public function displayValue(array $field, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        $type = FieldType::tryFrom($field['type'] ?? '');

        return match ($type) {
            FieldType::Checkbox => implode(', ', (array) $value),
            FieldType::Address => implode(', ', array_filter((array) $value)),
            FieldType::File => is_array($value) ? ($value['name'] ?? 'file').' ('.($value['size_kb'] ?? '?').' KB)' : '—',
            FieldType::Password => $this->decrypt((string) $value),
            FieldType::Signature => '[signature image]',
            default => is_array($value) ? json_encode($value) : (string) $value,
        };
    }

    private function decrypt(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '[unreadable]';
        }
    }

    public function render()
    {
        $schema = FormSchema::fromArray(
            ($this->form->publishedVersion ?? $this->form->latestVersion())?->schema_json
                ?? ['sections' => []]
        );

        // Table columns: the first few answerable fields of the current schema.
        $columns = array_slice($schema->answerableFields(), 0, 4);

        $submissions = $this->form->submissions()
            ->with('answers')
            ->when($this->search !== '', function ($query) {
                $query->whereHas('answers', function ($q) {
                    $q->where('value_text', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%');
                });
            })
            ->orderByDesc('submitted_at')
            ->paginate(15);

        $exports = \App\Models\FormExport::query()
            ->where('form_id', $this->form->id)
            ->latest()->limit(3)->get();

        return view('livewire.submissions.submissions-index', [
            'schema' => $schema,
            'columns' => $columns,
            'submissions' => $submissions,
            'exports' => $exports,
        ]);
    }
}
