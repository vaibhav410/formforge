<?php

use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\TrackEventController;
use App\Livewire\Ai\AiCreate;
use App\Livewire\Builder\FormBuilder;
use App\Livewire\Forms\FormsIndex;
use App\Livewire\Imports\ImportWizard;
use App\Livewire\Submissions\SubmissionsIndex;
use App\Livewire\Analytics\AnalyticsDashboard;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

// ── Public form (no auth) ─────────────────────────────────────
Route::get('/f/{form:public_id}', [PublicFormController::class, 'show'])
    ->name('forms.public.show');
Route::post('/f/{form:public_id}', [PublicFormController::class, 'submit'])
    ->middleware('throttle:public-submit')
    ->name('forms.public.submit');
Route::get('/f/{form:public_id}/thanks', [PublicFormController::class, 'thanks'])
    ->name('forms.public.thanks');
Route::post('/f/{form:public_id}/event', TrackEventController::class)
    ->middleware('throttle:public-events')
    ->name('forms.public.event');

// ── Authenticated app ─────────────────────────────────────────
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', FormsIndex::class)->name('dashboard');

    Route::get('/forms/ai', AiCreate::class)->name('forms.ai-create');
    Route::get('/forms/import', ImportWizard::class)->name('forms.import');

    Route::get('/forms/{form:uuid}/builder', FormBuilder::class)->name('forms.builder');
    Route::get('/forms/{form:uuid}/preview', PreviewController::class)->name('forms.preview');
    Route::get('/forms/{form:uuid}/submissions', SubmissionsIndex::class)->name('forms.submissions');
    Route::get('/forms/{form:uuid}/analytics', AnalyticsDashboard::class)->name('forms.analytics');
    Route::get('/exports/{export:uuid}/download', ExportDownloadController::class)->name('exports.download');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
