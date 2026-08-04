<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VersionSource;
use App\Enums\VersionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'version',
        'schema_json',
        'status',
        'source',
        'label',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'schema_json' => 'array',
            'version' => 'integer',
            'status' => VersionStatus::class,
            'source' => VersionSource::class,
            'published_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }
}
