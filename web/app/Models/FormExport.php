<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FormExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'form_id',
        'status',
        'format',
        'stored_path',
        'row_count',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'row_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FormExport $export) {
            $export->uuid ??= (string) Str::uuid();
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
