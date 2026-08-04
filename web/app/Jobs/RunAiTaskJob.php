<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTO\AiResultData;
use App\Enums\AiTaskType;
use App\Enums\TaskStatus;
use App\Enums\VersionSource;
use App\Exceptions\AiServiceException;
use App\Exceptions\InvalidSchemaException;
use App\Models\AiTask;
use App\Services\Ai\AiServiceClient;
use App\Services\FormService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one AI task (generate / edit / translate) off the web request.
 * The Livewire pages poll the ai_tasks row for status. The LLM result
 * goes through the same lenient sanitize → strict validate save path
 * as every other schema write.
 */
class RunAiTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 1; // retries happen inside the AI service, not here

    public function __construct(private readonly AiTask $task)
    {
    }

    public function handle(AiServiceClient $client, FormService $forms): void
    {
        $task = $this->task;
        $task->update(['status' => TaskStatus::Processing]);

        try {
            $result = match ($task->type) {
                AiTaskType::Generate => $client->generate($task->prompt),
                AiTaskType::Edit => $client->edit($task->prompt, $task->input_schema ?? []),
                AiTaskType::Translate => $client->translate($task->prompt, $task->input_schema ?? []),
            };

            $this->recordAttempts($task, $result->attempts);

            if ($task->type === AiTaskType::Generate) {
                $form = $forms->createFormFromSchema(
                    $task->user,
                    $result->schema,
                    VersionSource::Ai,
                    label: 'AI: '.\Illuminate\Support\Str::limit($task->prompt, 80),
                    lenient: true,
                );
                $task->form_id = $form->id;
            } else {
                $forms->saveDraftSchema(
                    $task->form,
                    $result->schema,
                    VersionSource::Ai,
                    label: 'AI: '.\Illuminate\Support\Str::limit($task->prompt, 80),
                    lenient: true,
                );
            }

            $task->fill([
                'status' => TaskStatus::Completed,
                'result_schema' => $result->schema,
                'model' => $result->model,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'latency_ms' => $result->totalLatencyMs,
                'attempts' => count($result->attempts),
            ])->save();
        } catch (AiServiceException $e) {
            $this->recordAttempts($task, $e->attempts);
            $this->fail($task, $e->getMessage(), count($e->attempts));
        } catch (InvalidSchemaException $e) {
            // The AI service validated it, but our stricter rules disagreed.
            $this->fail($task, 'Result failed final validation: '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->fail($task, $e->getMessage());

            throw $e;
        }
    }

    private function recordAttempts(AiTask $task, array $attempts): void
    {
        foreach ($attempts as $attempt) {
            $task->promptLogs()->create([
                'provider' => 'groq',
                'model' => $attempt['model'] ?? 'unknown',
                'attempt' => (int) ($attempt['attempt'] ?? 1),
                'outcome' => $attempt['outcome'] ?? 'unknown',
                'prompt_tokens' => $attempt['prompt_tokens'] ?? null,
                'completion_tokens' => $attempt['completion_tokens'] ?? null,
                'latency_ms' => $attempt['latency_ms'] ?? null,
                'response_excerpt' => $attempt['response_excerpt'] ?? null,
            ]);
        }
    }

    private function fail(AiTask $task, string $error, int $attempts = 0): void
    {
        $task->fill([
            'status' => TaskStatus::Failed,
            'error' => substr($error, 0, 2000),
            'attempts' => $attempts ?: $task->attempts,
        ])->save();
    }
}
