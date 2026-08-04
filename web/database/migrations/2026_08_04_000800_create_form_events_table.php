<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only analytics stream. High volume by design: rows are
        // rolled up into form_analytics_daily by a queued job and can be
        // pruned after aggregation.
        Schema::create('form_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->nullable()->constrained('form_versions')->nullOnDelete();
            // Anonymous visitor cookie — ties view→start→submit funnels.
            $table->string('visitor_id', 64);
            $table->string('event', 20); // view|start|field_focus|submit|abandon
            // For field-level drop-off analysis.
            $table->string('field_key', 100)->nullable();
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');

            $table->index(['form_id', 'event', 'created_at']);
            $table->index(['form_id', 'visitor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_events');
    }
};
