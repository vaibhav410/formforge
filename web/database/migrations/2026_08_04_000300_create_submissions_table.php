<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            // The exact schema snapshot this submission was validated
            // against — answers stay renderable after the form changes.
            $table->foreignId('form_version_id')->constrained('form_versions')->restrictOnDelete();
            // SHA-256 of ip+day salt: enough for rate limiting and dedupe
            // without storing raw PII.
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            // Submission list: per-form, newest first, paginated.
            $table->index(['form_id', 'submitted_at']);
            // Spam throttling lookups.
            $table->index(['form_id', 'ip_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
