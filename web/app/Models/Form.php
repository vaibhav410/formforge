<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Form extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'settings',
        'published_version_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FormStatus::class,
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Form $form) {
            $form->uuid ??= (string) Str::uuid();
            $form->public_id ??= self::generatePublicId();
        });
    }

    /** Collision-checked short slug for the public fill URL. */
    public static function generatePublicId(): string
    {
        do {
            $id = Str::lower(Str::random(10));
        } while (self::withTrashed()->where('public_id', $id)->exists());

        return $id;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderByDesc('version');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'published_version_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(FormEvent::class);
    }

    public function dailyAnalytics(): HasMany
    {
        return $this->hasMany(FormAnalyticsDaily::class);
    }

    /** The version the builder edits: the newest draft. */
    public function latestDraftVersion(): ?FormVersion
    {
        return $this->versions()
            ->where('status', \App\Enums\VersionStatus::Draft)
            ->orderByDesc('version')
            ->first();
    }

    public function latestVersion(): ?FormVersion
    {
        return $this->versions()->orderByDesc('version')->first();
    }

    public function isPublished(): bool
    {
        return $this->status === FormStatus::Published && $this->published_version_id !== null;
    }

    public function publicUrl(): string
    {
        return route('forms.public.show', $this->public_id);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when(
            filled($term),
            fn (Builder $q) => $q->where('title', 'like', str_replace(['%', '_'], ['\%', '\_'], trim((string) $term)).'%')
        );
    }
}
