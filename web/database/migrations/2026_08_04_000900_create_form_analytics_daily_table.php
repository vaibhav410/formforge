<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pre-aggregated rollups so the analytics dashboard reads a handful
        // of rows instead of scanning form_events.
        Schema::create('form_analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('starts')->default(0);
            $table->unsignedInteger('submissions')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('avg_duration_seconds')->nullable();
            // { "field_key": abandon_count } for the drop-off chart.
            $table->json('drop_off')->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_analytics_daily');
    }
};
