<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormAnalyticsDaily extends Model
{
    use HasFactory;

    protected $table = 'form_analytics_daily';

    protected $fillable = [
        'form_id',
        'date',
        'views',
        'starts',
        'submissions',
        'unique_visitors',
        'avg_duration_seconds',
        'drop_off',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'views' => 'integer',
            'starts' => 'integer',
            'submissions' => 'integer',
            'unique_visitors' => 'integer',
            'avg_duration_seconds' => 'integer',
            'drop_off' => 'array',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
