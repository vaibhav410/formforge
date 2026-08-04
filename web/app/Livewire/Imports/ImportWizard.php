<?php

declare(strict_types=1);

namespace App\Livewire\Imports;

use Livewire\Attributes\Layout;
use Livewire\Component;

/** Placeholder — replaced by the full import wizard (module: Import). */
#[Layout('layouts.app')]
class ImportWizard extends Component
{
    public function render()
    {
        return view('livewire.imports.import-wizard');
    }
}
