<?php

use App\Livewire\Builder\FormBuilder;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ownedForm(User $user): App\Models\Form
{
    return app(FormService::class)->createForm($user, 'Builder test');
}

test('the owner can open the builder', function () {
    $user = User::factory()->create();
    $form = ownedForm($user);

    Livewire::actingAs($user)
        ->test(FormBuilder::class, ['form' => $form])
        ->assertOk()
        ->assertSet('schema.title', 'Builder test');
});

test('another user is forbidden from the builder', function () {
    $form = ownedForm(User::factory()->create());

    Livewire::actingAs(User::factory()->create())
        ->test(FormBuilder::class, ['form' => $form])
        ->assertForbidden();
});

test('adding a field autosaves it into the draft version', function () {
    $user = User::factory()->create();
    $form = ownedForm($user);
    $sectionId = $form->latestDraftVersion()->schema_json['sections'][0]['id'];

    Livewire::actingAs($user)
        ->test(FormBuilder::class, ['form' => $form])
        ->call('addField', 'email', $sectionId)
        ->assertSet('saveState', 'saved');

    $fields = $form->latestDraftVersion()->refresh()->schema_json['sections'][0]['fields'];
    expect($fields)->toHaveCount(1)
        ->and($fields[0]['type'])->toBe('email');
});

test('duplicate, move and remove keep keys unique and order correct', function () {
    $user = User::factory()->create();
    $form = ownedForm($user);
    $sectionId = $form->latestDraftVersion()->schema_json['sections'][0]['id'];

    $component = Livewire::actingAs($user)
        ->test(FormBuilder::class, ['form' => $form])
        ->call('addField', 'text', $sectionId)
        ->call('addField', 'number', $sectionId);

    $fields = $component->get('schema')['sections'][0]['fields'];
    $component->call('duplicateField', $fields[0]['id']);

    $keys = array_column($component->get('schema')['sections'][0]['fields'], 'key');
    expect($keys)->toHaveCount(3)->and($keys)->toBe(array_unique($keys));

    $component->call('moveField', $fields[1]['id'], $sectionId, 0);
    expect($component->get('schema')['sections'][0]['fields'][0]['type'])->toBe('number');

    $component->call('removeField', $fields[1]['id']);
    expect($component->get('schema')['sections'][0]['fields'])->toHaveCount(2);
});

test('invalid JSON in the editor surfaces errors and persists nothing', function () {
    $user = User::factory()->create();
    $form = ownedForm($user);
    $before = $form->latestDraftVersion()->schema_json;

    $component = Livewire::actingAs($user)
        ->test(FormBuilder::class, ['form' => $form])
        ->set('jsonText', '{"broken": ')
        ->call('applyJson');

    expect($component->get('jsonError'))->not->toBeNull()
        ->and($form->latestDraftVersion()->refresh()->schema_json)->toBe($before);
});

test('valid JSON with an invalid schema shows validator errors and blocks publish', function () {
    $user = User::factory()->create();
    $form = ownedForm($user);

    $bad = $form->latestDraftVersion()->schema_json;
    $bad['sections'][0]['fields'][] = ['id' => 'fld_zzzzzzzz', 'key' => 'x', 'type' => 'radio', 'label' => 'Pick', 'options' => []];

    $component = Livewire::actingAs($user)
        ->test(FormBuilder::class, ['form' => $form])
        ->set('jsonText', json_encode($bad))
        ->call('applyJson')
        ->assertSet('saveState', 'error');

    expect($component->get('schemaErrors'))->not->toBe([]);

    $component->call('publish');
    expect($form->refresh()->isPublished())->toBeFalse();
});

test('publish makes the form live and undo snapshots can be applied', function () {
    $user = User::factory()->create();
    $form = ownedForm($user);
    $sectionId = $form->latestDraftVersion()->schema_json['sections'][0]['id'];

    $component = Livewire::actingAs($user)
        ->test(FormBuilder::class, ['form' => $form])
        ->call('addField', 'text', $sectionId);

    $snapshotBefore = $component->get('schema');

    $component->call('addField', 'email', $sectionId)
        ->call('publish');
    expect($form->refresh()->isPublished())->toBeTrue();

    // Undo path: applying an older snapshot writes a fresh draft.
    $component->call('applySnapshot', $snapshotBefore);
    expect(count($form->latestDraftVersion()->schema_json['sections'][0]['fields']))->toBe(1);
});
