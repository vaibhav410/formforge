<?php

use App\Enums\TaskStatus;
use App\Livewire\Imports\ImportWizard;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the full wizard flow: upload, parse, map, commit creates the form', function () {
    config(['queue.default' => 'sync']);
    $user = User::factory()->create();

    $file = UploadedFile::fake()->createWithContent(
        'job-application.docx',
        file_get_contents(base_path('../samples/job-application.docx'))
    );

    $component = Livewire::actingAs($user)
        ->test(ImportWizard::class)
        ->set('upload', $file); // triggers updatedUpload -> queued job (sync)

    $import = Import::latest('id')->first();
    expect($import)->not->toBeNull()
        ->and($import->status)->toBe(TaskStatus::PreviewReady);

    // wire:poll tick builds the mapping table
    $component->call('checkImport');
    expect($component->get('mapping'))->not->toBe([])
        ->and($component->get('formTitle'))->toBe('Job Application Form');

    // The "Create form (N fields)" button. Regression note: the action
    // must NOT be named "commit" — Livewire's JS $wire has an internal
    // commit (request flush) that shadows it and the click goes nowhere.
    $component->call('commitImport');

    $import->refresh();
    expect($component->get('error'))->toBeNull()
        ->and($import->status)->toBe(TaskStatus::Committed)
        ->and($import->form_id)->not->toBeNull();

    $component->assertRedirect(route('forms.builder', $import->form));
});
