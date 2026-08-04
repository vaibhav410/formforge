<?php

declare(strict_types=1);

namespace App\Livewire\Ai;

use App\Enums\AiTaskType;
use App\Enums\TaskStatus;
use App\Jobs\RunAiTaskJob;
use App\Models\AiTask;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Describe your form" page. Generation runs as a queued job; this
 * page polls the task row and redirects to the builder on success.
 */
#[Layout('layouts.app')]
class AiCreate extends Component
{
    public string $prompt = '';

    public ?string $taskUuid = null;

    public ?string $error = null;

    /** @var list<string> */
    public array $examples = [
        'Internship application with education history, skills and resume upload',
        'Customer satisfaction survey with a 5-star rating and follow-up question when the rating is low',
        'Event registration with ticket type, dietary requirements and an emergency contact section',
        'Job application form for a senior engineer with portfolio links and availability date',
    ];

    public function generate(): void
    {
        $this->validate(['prompt' => ['required', 'string', 'min:10', 'max:2000']]);
        $this->error = null;

        // The expensive path is quota-limited per user.
        $key = 'ai-generation:'.auth()->id();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, (int) config('formforge.rate_limits.ai_generations_per_hour'))) {
            $this->error = 'AI generation limit reached — try again in a while.';

            return;
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 3600);

        $task = AiTask::create([
            'user_id' => auth()->id(),
            'type' => AiTaskType::Generate,
            'status' => TaskStatus::Queued,
            'prompt' => $this->prompt,
        ]);

        RunAiTaskJob::dispatch($task);
        $this->taskUuid = $task->uuid;
    }

    public function useExample(int $index): void
    {
        $this->prompt = $this->examples[$index] ?? '';
    }

    /** wire:poll target while a task is in flight. */
    public function checkTask(): void
    {
        $task = $this->currentTask();
        if ($task === null) {
            return;
        }

        if ($task->status === TaskStatus::Completed && $task->form_id !== null) {
            $this->redirectRoute('forms.builder', $task->form, navigate: true);
        }

        if ($task->status === TaskStatus::Failed) {
            $this->error = $task->error ?? 'Generation failed.';
            $this->taskUuid = null;
        }
    }

    public function currentTask(): ?AiTask
    {
        return $this->taskUuid === null
            ? null
            : AiTask::query()->where('uuid', $this->taskUuid)->first();
    }

    public function render()
    {
        return view('livewire.ai.ai-create', [
            'task' => $this->currentTask(),
            'recentTasks' => AiTask::query()
                ->where('user_id', auth()->id())
                ->where('type', AiTaskType::Generate)
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }
}
