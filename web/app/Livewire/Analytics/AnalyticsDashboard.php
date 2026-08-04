<?php

declare(strict_types=1);

namespace App\Livewire\Analytics;

use App\Models\Form;
use App\Services\AnalyticsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class AnalyticsDashboard extends Component
{
    #[Locked]
    public Form $form;

    public function mount(Form $form): void
    {
        $this->authorize('view', $form);
        $this->form = $form;
    }

    public function render(AnalyticsService $analytics)
    {
        return view('livewire.analytics.analytics-dashboard', [
            'totals' => $analytics->totals($this->form),
            'series' => $analytics->dailySeries($this->form),
            'dropOff' => $analytics->dropOff($this->form),
        ]);
    }
}
