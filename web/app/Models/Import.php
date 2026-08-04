<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportType;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Import extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'form_id',
        'type',
        'status',
        'original_filename',
        'stored_path',
        'size_bytes',
        'parsed_schema',
        'issues',
        'ai_used',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => TaskStatus::class,
            'parsed_schema' => 'array',
            'issues' => 'array',
            'ai_used' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Import $import) {
            $import->uuid ??= (string) Str::uuid();
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
}
