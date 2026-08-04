<?php

use App\Enums\FieldType;
use App\Enums\FormStatus;
use App\Enums\VersionSource;
use App\Enums\VersionStatus;
use App\Models\User;
use App\Schema\SchemaFactory;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function formService(): FormService
{
    return app(FormService::class);
}

function richSchema(): array
{
    $schema = SchemaFactory::emptySchema('Lifecycle form');
    $schema['sections'][0]['fields'] = [
        SchemaFactory::field(FieldType::Text, ['key' => 'name', 'label' => 'Name', 'required' => true]),
        SchemaFactory::field(FieldType::Email, ['key' => 'email', 'label' => 'Email']),
    ];

    return $schema;
}

test('creating a form produces version 1 as draft', function () {
    $user = User::factory()->create();
    $form = formService()->createFormFromSchema($user, richSchema(), VersionSource::Manual);

    expect($form->status)->toBe(FormStatus::Draft)
        ->and($form->versions)->toHaveCount(1)
        ->and($form->latestDraftVersion()->version)->toBe(1)
        ->and($form->public_id)->toHaveLength(10);
});

test('publishing promotes the draft and edits open a new draft version', function () {
    $user = User::factory()->create();
    $service = formService();
    $form = $service->createFormFromSchema($user, richSchema(), VersionSource::Manual);

    $service->publish($form->refresh());
    $form->refresh();
    expect($form->status)->toBe(FormStatus::Published)
        ->and($form->publishedVersion->version)->toBe(1)
        ->and($form->publishedVersion->status)->toBe(VersionStatus::Published);

    // Next save cannot mutate the published snapshot.
    $edited = richSchema();
    $edited['title'] = 'Lifecycle form v2';
    $service->saveDraftSchema($form, $edited);
    $form->refresh();

    expect($form->latestDraftVersion()->version)->toBe(2)
        ->and($form->publishedVersion->version)->toBe(1)
        ->and($form->publishedVersion->schema_json['title'])->toBe('Lifecycle form');
});

test('rollback publishes the old schema as a new version', function () {
    $user = User::factory()->create();
    $service = formService();
    $form = $service->createFormFromSchema($user, richSchema(), VersionSource::Manual);
    $service->publish($form->refresh());

    $v2 = richSchema();
    $v2['title'] = 'Changed title';
    $service->saveDraftSchema($form->refresh(), $v2);
    $service->publish($form->refresh());
    $form->refresh();
    expect($form->publishedVersion->version)->toBe(2);

    $service->rollbackTo($form, $form->versions()->where('version', 1)->first());
    $form->refresh();

    expect($form->publishedVersion->version)->toBe(3)
        ->and($form->publishedVersion->schema_json['title'])->toBe('Lifecycle form')
        ->and($form->publishedVersion->source)->toBe(VersionSource::Rollback)
        ->and($form->versions()->count())->toBe(3);
});

test('an invalid schema is never persisted', function () {
    $user = User::factory()->create();
    $schema = richSchema();
    $schema['sections'][0]['fields'][] = ['type' => 'martian', 'key' => 'x', 'label' => 'X'];

    expect(fn () => formService()->createFormFromSchema($user, $schema, VersionSource::Manual))
        ->toThrow(App\Exceptions\InvalidSchemaException::class)
        ->and($user->forms()->count())->toBe(0);
});
