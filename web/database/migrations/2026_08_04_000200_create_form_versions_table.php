<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            // The single source of truth. Builder, public renderer,
            // server-side validation and exports all derive from this.
            $table->json('schema_json');
            $table->string('status', 20)->default('draft'); // draft|published|superseded
            // Where this version came from: manual|ai|import|rollback.
            $table->string('source', 20)->default('manual');
            // Human-readable changelog line, e.g. "AI: added emergency contact".
            $table->string('label')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'version']);
            $table->index(['form_id', 'status']);
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->foreign('published_version_id')
                ->references('id')->on('form_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
        });
        Schema::dropIfExists('form_versions');
    }
};
