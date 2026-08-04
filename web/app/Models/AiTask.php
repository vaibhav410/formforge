<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiTaskType;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'form_id',
        'type',
        'status',
        'prompt',
        'input_schema',
        'result_schema',
        'error',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'latency_ms',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'type' => AiTaskType::class,
            'status' => TaskStatus::class,
            'input_schema' => 'array',
            'result_schema' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiTask $task) {
            $task->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function promptLogs(): HasMany
    {
        return $this->hasMany(PromptLog::class);
    }
}
