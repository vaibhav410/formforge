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

test('commit survives numbered-question keys from real documents', function () {
    // Regression: a parsed schema whose keys start with digits (numbered
    // questions) failed strict validation on commit before slugKey
    // enforced the leading-letter rule.
    $user = User::factory()->create();

    $parsedSchema = \App\Schema\SchemaFactory::emptySchema('Numbered Questionnaire');
    $parsedSchema['sections'] = [\App\Schema\SchemaFactory::section('Section 14', ['fields' => [
        \App\Schema\SchemaFactory::field(\App\Enums\FieldType::Text, ['label' => '1. Full Name', 'key' => '1_full_name']),
        \App\Schema\SchemaFactory::field(\App\Enums\FieldType::Email, ['label' => '2. Email', 'key' => '2_email']),
        \App\Schema\SchemaFactory::field(\App\Enums\FieldType::Text, ['label' => 'प्रश्न तीन', 'key' => '']),
    ]])];

    $import = Import::create([
        'user_id' => $user->id,
        'type' => \App\Enums\ImportType::Word,
        'status' => TaskStatus::PreviewReady,
        'original_filename' => 'numbered.docx',
        'stored_path' => 'imports/fake.docx',
        'size_bytes' => 1000,
        'parsed_schema' => $parsedSchema,
    ]);

    $component = Livewire::actingAs($user)
        ->test(\App\Livewire\Imports\ImportWizard::class)
        ->set('importUuid', $import->uuid)
        ->call('checkImport')
        ->call('commitImport');

    $import->refresh();
    expect($component->get('error'))->toBeNull()
        ->and($import->status)->toBe(TaskStatus::Committed)
        ->and($import->form_id)->not->toBeNull();

    $keys = \App\Schema\FormSchema::fromArray($import->form->latestVersion()->schema_json)->fieldKeys();
    foreach ($keys as $key) {
        expect($key)->toMatch('/^[a-z][a-z0-9_]{0,63}$/');
    }
});
