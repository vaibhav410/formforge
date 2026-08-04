<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null for "generate from scratch"; set for edit/translate.
            $table->foreignId('form_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // generate|edit|translate
            $table->string('status', 20)->default('queued'); // queued|processing|completed|failed
            $table->text('prompt');
            // Snapshot of the schema the edit/translate started from.
            $table->json('input_schema')->nullable();
            $table->json('result_schema')->nullable();
            $table->text('error')->nullable();
            // Observability: filled in from the AI service response.
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            // Builder polls "my latest task" by uuid; dashboard lists by user.
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tasks');
    }
};
