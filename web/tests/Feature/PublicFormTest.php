<?php

use App\Enums\FieldType;
use App\Enums\VersionSource;
use App\Models\User;
use App\Schema\SchemaFactory;
use App\Services\FormService;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function publishForm(array $fields): App\Models\Form
{
    $schema = SchemaFactory::emptySchema('Public test form');
    $schema['sections'][0]['fields'] = $fields;

    $service = app(FormService::class);
    $form = $service->createFormFromSchema(User::factory()->create(), $schema, VersionSource::Manual);
    $service->publish($form->refresh());

    return $form->refresh();
}

function standardFields(): array
{
    return [
        SchemaFactory::field(FieldType::Text, ['key' => 'name', 'label' => 'Name', 'required' => true]),
        SchemaFactory::field(FieldType::Email, ['key' => 'email', 'label' => 'Email', 'required' => true]),
        SchemaFactory::field(FieldType::Textarea, ['key' => 'reason', 'label' => 'Why?', 'required' => true, 'logic' => [
            'action' => 'show', 'match' => 'all',
            'conditions' => [['field' => 'name', 'operator' => 'equals', 'value' => 'special']],
        ]]),
    ];
}

function submitToken(): string
{
    return app(SubmissionService::class)->makeRenderToken();
}

test('a published form renders publicly and logs a view', function () {
    $form = publishForm(standardFields());

    $this->get(route('forms.public.show', $form))
        ->assertOk()
        ->assertSee('Public test form')
        ->assertSee('Name');

    expect($form->events()->where('event', 'view')->count())->toBe(1)
        ->and($form->refresh()->views_count)->toBe(1);
});

test('a draft form 404s publicly', function () {
    $schema = SchemaFactory::emptySchema('Draft only');
    $form = app(FormService::class)->createFormFromSchema(User::factory()->create(), $schema, VersionSource::Manual);

    $this->get(route('forms.public.show', $form))->assertNotFound();
});

test('instant submissions are treated as bots: silent success, nothing stored', function () {
    $form = publishForm(standardFields());

    // Token issued this instant → fill time under 3s → bot heuristic.
    $this->post(route('forms.public.submit', $form), [
        '_rt' => submitToken(),
        'name' => 'Alice',
        'email' => 'alice@example.com',
    ])->assertRedirect(route('forms.public.thanks', $form));

    expect($form->submissions()->count())->toBe(0);
});

test('submission stores answers once the minimum fill time has passed', function () {
    $form = publishForm(standardFields());
    $token = submitToken();

    $this->travel(30)->seconds();
    $response = $this->post(route('forms.public.submit', $form), [
        '_rt' => $token,
        'name' => 'Alice',
        'email' => 'alice@example.com',
    ]);
    $this->travelBack();

    $response->assertRedirect(route('forms.public.thanks', $form));
    $submission = $form->submissions()->with('answers')->first();

    expect($submission)->not->toBeNull()
        ->and($submission->answersByKey())->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($submission->duration_seconds)->toBeGreaterThanOrEqual(29)
        ->and($submission->form_version_id)->toBe($form->published_version_id);
});

test('server-side validation rejects bad input derived from the schema', function () {
    $form = publishForm(standardFields());
    $token = submitToken();

    $this->travel(10)->seconds();
    $this->post(route('forms.public.submit', $form), [
        '_rt' => $token,
        'name' => '',
        'email' => 'not-an-email',
    ])->assertSessionHasErrors(['name', 'email']);
    $this->travelBack();

    expect($form->submissions()->count())->toBe(0);
});

test('conditionally hidden required fields are not enforced and their answers are discarded', function () {
    $form = publishForm(standardFields());
    $token = submitToken();

    $this->travel(10)->seconds();
    // "reason" is required but only visible when name == special.
    $this->post(route('forms.public.submit', $form), [
        '_rt' => $token,
        'name' => 'ordinary',
        'email' => 'a@b.com',
        'reason' => 'browser sent this anyway',
    ])->assertSessionDoesntHaveErrors();
    $this->travelBack();

    $submission = $form->submissions()->with('answers')->first();
    expect($submission->answersByKey())->not->toHaveKey('reason');
});

test('the conditional field IS enforced when its condition matches', function () {
    $form = publishForm(standardFields());
    $token = submitToken();

    $this->travel(10)->seconds();
    $this->post(route('forms.public.submit', $form), [
        '_rt' => $token,
        'name' => 'special',
        'email' => 'a@b.com',
    ])->assertSessionHasErrors(['reason']);
    $this->travelBack();
});

test('honeypot submissions pretend success and store nothing', function () {
    $form = publishForm(standardFields());
    $token = submitToken();

    $this->travel(10)->seconds();
    $this->post(route('forms.public.submit', $form), [
        '_rt' => $token,
        '_website' => 'http://spam.example',
        'name' => 'Bot',
        'email' => 'bot@spam.example',
    ])->assertRedirect(route('forms.public.thanks', $form));
    $this->travelBack();

    expect($form->submissions()->count())->toBe(0);
});
