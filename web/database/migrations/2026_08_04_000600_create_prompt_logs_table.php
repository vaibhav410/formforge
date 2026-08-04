<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per LLM round-trip (a single ai_task may take several:
        // initial call + JSON repairs + retries). Written by Laravel from
        // the per-attempt telemetry the AI service returns.
        Schema::create('prompt_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_task_id')->nullable()->constrained('ai_tasks')->nullOnDelete();
            $table->string('provider', 30)->default('groq');
            $table->string('model');
            $table->unsignedTinyInteger('attempt')->default(1);
            // success|invalid_json|schema_invalid|provider_error
            $table->string('outcome', 30);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            // First ~64KB of the raw response — enough to debug bad JSON
            // without archiving entire transcripts forever.
            $table->text('response_excerpt')->nullable();
            $table->timestamps();

            $table->index('ai_task_id');
            $table->index(['model', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_logs');
    }
};
