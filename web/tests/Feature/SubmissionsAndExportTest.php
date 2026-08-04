<?php

use App\Enums\FieldType;
use App\Enums\TaskStatus;
use App\Enums\VersionSource;
use App\Jobs\ExportSubmissionsJob;
use App\Livewire\Submissions\SubmissionsIndex;
use App\Models\FormExport;
use App\Models\User;
use App\Schema\SchemaFactory;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function formWithSubmissions(User $owner, int $count = 3): App\Models\Form
{
    $schema = SchemaFactory::emptySchema('Answers form');
    $schema['sections'][0]['fields'] = [
        SchemaFactory::field(FieldType::Text, ['key' => 'name', 'label' => 'Name', 'required' => true]),
        SchemaFactory::field(FieldType::Checkbox, ['key' => 'tags', 'label' => 'Tags', 'options' => [
            ['label' => 'One', 'value' => 'one'], ['label' => 'Two', 'value' => 'two'],
        ]]),
    ];

    $service = app(FormService::class);
    $form = $service->createFormFromSchema($owner, $schema, VersionSource::Manual);
    $service->publish($form->refresh());
    $form->refresh();

    foreach (range(1, $count) as $i) {
        $submission = $form->submissions()->create([
            'form_version_id' => $form->published_version_id,
            'submitted_at' => now()->subMinutes($i),
            'duration_seconds' => 60 + $i,
        ]);
        $submission->answers()->create([
            'form_id' => $form->id,
            'field_key' => 'name',
            'field_type' => 'text',
            'value_text' => "Person $i",
        ]);
        $submission->answers()->create([
            'form_id' => $form->id,
            'field_key' => 'tags',
            'field_type' => 'checkbox',
            'value_json' => ['one'],
        ]);
        $form->increment('submissions_count');
    }

    return $form->refresh();
}

test('the owner sees a searchable submissions table', function () {
    $owner = User::factory()->create();
    $form = formWithSubmissions($owner);

    Livewire::actingAs($owner)
        ->test(SubmissionsIndex::class, ['form' => $form])
        ->assertOk()
        ->assertSee('Person 1')
        ->set('search', 'Person 2')
        ->assertSee('Person 2')
        ->assertDontSee('Person 1');
});

test('other users cannot open someone else\'s submissions', function () {
    $form = formWithSubmissions(User::factory()->create());

    Livewire::actingAs(User::factory()->create())
        ->test(SubmissionsIndex::class, ['form' => $form])
        ->assertForbidden();
});

test('the CSV export job writes a schema-ordered file', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $form = formWithSubmissions($owner, 2);

    $export = FormExport::create(['user_id' => $owner->id, 'form_id' => $form->id]);
    (new ExportSubmissionsJob($export))->handle();
    $export->refresh();

    expect($export->status)->toBe(TaskStatus::Completed)
        ->and($export->row_count)->toBe(2);

    $csv = Storage::disk('local')->get($export->stored_path);
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toContain('Name')->toContain('Tags')
        ->and($csv)->toContain('Person 1')
        ->and($csv)->toContain('one');
});

test('export download is owner-only', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $form = formWithSubmissions($owner, 1);
    $export = FormExport::create(['user_id' => $owner->id, 'form_id' => $form->id]);
    (new ExportSubmissionsJob($export))->handle();

    $this->actingAs(User::factory()->create())
        ->get(route('exports.download', $export->refresh()))
        ->assertForbidden();

    $this->actingAs($owner)
        ->get(route('exports.download', $export))
        ->assertOk()
        ->assertHeader('content-disposition');
});
