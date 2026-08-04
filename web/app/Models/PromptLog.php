<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_task_id',
        'provider',
        'model',
        'attempt',
        'outcome',
        'prompt_tokens',
        'completion_tokens',
        'latency_ms',
        'response_excerpt',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function aiTask(): BelongsTo
    {
        return $this->belongsTo(AiTask::class);
    }
}
