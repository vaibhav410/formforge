<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Set once the user commits the previewed schema into a form.
            $table->foreignId('form_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10); // word|excel
            // queued|processing|preview_ready|committed|failed
            $table->string('status', 20)->default('queued');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedInteger('size_bytes');
            // Parser output awaiting user review on the mapping screen.
            $table->json('parsed_schema')->nullable();
            // Blocks the parser could not confidently map, surfaced in the UI.
            $table->json('issues')->nullable();
            $table->boolean('ai_used')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
