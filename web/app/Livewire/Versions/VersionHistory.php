<?php

declare(strict_types=1);

namespace App\Livewire\Versions;

use App\Models\Form;
use App\Models\FormVersion;
use App\Schema\FormSchema;
use App\Services\FormService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Version history with per-version field diffs and one-click rollback.
 * Rollback never rewrites history: it publishes a NEW version carrying
 * the old schema (source=rollback).
 */
#[Layout('layouts.app')]
class VersionHistory extends Component
{
    #[Locked]
    public Form $form;

    public function mount(Form $form): void
    {
        $this->authorize('update', $form);
        $this->form = $form;
    }

    public function rollback(int $versionId, FormService $formService): void
    {
        $target = $this->form->versions()->findOrFail($versionId);
        $formService->rollbackTo($this->form, $target);
        $this->form->refresh();

        session()->flash('status', "Rolled back to v{$target->version} — published as v{$this->form->latestVersion()->version}.");
    }

    /**
     * Field-level diff against the previous version.
     *
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    public function diff(FormVersion $version, ?FormVersion $previous): array
    {
        $current = $this->fieldsByKey($version);
        $before = $previous === null ? [] : $this->fieldsByKey($previous);

        $added = array_values(array_diff(array_keys($current), array_keys($before)));
        $removed = array_values(array_diff(array_keys($before), array_keys($current)));
        $changed = [];
        foreach (array_intersect_key($current, $before) as $key => $field) {
            if (json_encode($field) !== json_encode($before[$key])) {
                $changed[] = $key;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /** @return array<string, array> */
    private function fieldsByKey(FormVersion $version): array
    {
        $fields = [];
        foreach (FormSchema::fromArray($version->schema_json)->fields() as $field) {
            $fields[$field['key']] = $field;
        }

        return $fields;
    }

    public function render()
    {
        $versions = $this->form->versions()->get(); // ordered desc by relation

        return view('livewire.versions.version-history', [
            'versions' => $versions,
            'ordered' => $versions->sortBy('version')->values(),
        ]);
    }
}
