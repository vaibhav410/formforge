<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\Form;
use App\Services\FormService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Dashboard: the user's forms with search, status filter and stats. */
#[Layout('layouts.app')]
class FormsIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[\Livewire\Attributes\On('create-form')]
    public function createForm(FormService $formService): void
    {
        $form = $formService->createForm(auth()->user());

        $this->redirectRoute('forms.builder', $form, navigate: true);
    }

    public function deleteForm(int $formId): void
    {
        $form = Form::query()->findOrFail($formId);
        $this->authorize('delete', $form);
        $form->delete();
    }

    public function render()
    {
        $forms = Form::query()
            ->ownedBy(auth()->user())
            ->search($this->search)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('updated_at')
            ->paginate(12);

        return view('livewire.forms.forms-index', ['forms' => $forms]);
    }
}
