<?php

use App\Enums\AiTaskType;
use App\Enums\TaskStatus;
use App\Jobs\RunAiTaskJob;
use App\Models\AiTask;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function aiSchema(): array
{
    return [
        'schema_version' => 1,
        'title' => 'AI generated form',
        'description' => null,
        'settings' => ['submit_label' => 'Submit', 'success_message' => 'Thanks!'],
        'sections' => [[
            'title' => 'Main',
            'description' => null,
            'fields' => [[
                'key' => 'email', 'type' => 'email', 'label' => 'Email',
                'required' => true, 'options' => null,
            ]],
        ]],
    ];
}

function fakeAiSuccess(array $schema): void
{
    Http::fake([
        '*/v1/forms/*' => Http::response([
            'schema' => $schema,
            'model' => 'llama-3.3-70b-versatile',
            'total_latency_ms' => 1234,
            'prompt_tokens' => 100,
            'completion_tokens' => 200,
            'attempts' => [[
                'attempt' => 1, 'model' => 'llama-3.3-70b-versatile',
                'outcome' => 'success', 'latency_ms' => 1234,
                'prompt_tokens' => 100, 'completion_tokens' => 200,
                'response_excerpt' => null,
            ]],
        ]),
    ]);
}

test('a generate task creates a form and records prompt logs', function () {
    fakeAiSuccess(aiSchema());
    $user = User::factory()->create();

    $task = AiTask::create([
        'user_id' => $user->id,
        'type' => AiTaskType::Generate,
        'status' => TaskStatus::Queued,
        'prompt' => 'a contact form',
    ]);

    (new RunAiTaskJob($task))->handle(app(App\Services\Ai\AiServiceClient::class), app(FormService::class));
    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Completed)
        ->and($task->model)->toBe('llama-3.3-70b-versatile')
        ->and($task->promptLogs)->toHaveCount(1)
        ->and($task->form)->not->toBeNull()
        ->and($task->form->latestVersion()->schema_json['title'])->toBe('AI generated form')
        ->and($task->form->latestVersion()->source->value)->toBe('ai');
});

test('an edit task saves a new draft on the existing form', function () {
    $user = User::factory()->create();
    $form = app(FormService::class)->createForm($user, 'Editable');

    $edited = aiSchema();
    $edited['title'] = 'Edited by AI';
    fakeAiSuccess($edited);

    $task = AiTask::create([
        'user_id' => $user->id,
        'form_id' => $form->id,
        'type' => AiTaskType::Edit,
        'status' => TaskStatus::Queued,
        'prompt' => 'rename the form',
        'input_schema' => $form->latestVersion()->schema_json,
    ]);

    (new RunAiTaskJob($task))->handle(app(App\Services\Ai\AiServiceClient::class), app(FormService::class));

    expect($task->refresh()->status)->toBe(TaskStatus::Completed)
        ->and($form->latestVersion()->refresh()->schema_json['title'])->toBe('Edited by AI');
});

test('a 422 from the AI service marks the task failed with its attempts', function () {
    Http::fake([
        '*/v1/forms/*' => Http::response([
            'detail' => 'Could not produce a valid schema after 3 attempt(s)',
            'attempts' => [
                ['attempt' => 1, 'model' => 'llama-3.3-70b-versatile', 'outcome' => 'invalid_json', 'latency_ms' => 500],
                ['attempt' => 2, 'model' => 'llama-3.3-70b-versatile', 'outcome' => 'schema_invalid', 'latency_ms' => 600],
                ['attempt' => 3, 'model' => 'llama-3.1-8b-instant', 'outcome' => 'invalid_json', 'latency_ms' => 300],
            ],
        ], 422),
    ]);

    $user = User::factory()->create();
    $task = AiTask::create([
        'user_id' => $user->id,
        'type' => AiTaskType::Generate,
        'status' => TaskStatus::Queued,
        'prompt' => 'impossible request',
    ]);

    (new RunAiTaskJob($task))->handle(app(App\Services\Ai\AiServiceClient::class), app(FormService::class));
    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error)->toContain('Could not produce')
        ->and($task->promptLogs)->toHaveCount(3)
        ->and($user->forms()->count())->toBe(0);
});

test('a hallucinated field type from the AI is repaired leniently, not rejected', function () {
    $schema = aiSchema();
    $schema['sections'][0]['fields'][] = [
        'key' => 'skills', 'type' => 'multiselect', 'label' => 'Skills',
        'options' => [['label' => 'PHP', 'value' => 'php']],
    ];
    fakeAiSuccess($schema);

    $user = User::factory()->create();
    $task = AiTask::create([
        'user_id' => $user->id,
        'type' => AiTaskType::Generate,
        'status' => TaskStatus::Queued,
        'prompt' => 'form with skills',
    ]);

    (new RunAiTaskJob($task))->handle(app(App\Services\Ai\AiServiceClient::class), app(FormService::class));

    $fields = $task->refresh()->form->latestVersion()->schema_json['sections'][0]['fields'];
    expect($task->status)->toBe(TaskStatus::Completed)
        ->and($fields[1]['type'])->toBe('checkbox');
});
