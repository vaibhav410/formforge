<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            // Short slug used in the public fill URL (/f/{public_id}).
            $table->string('public_id', 20)->unique();
            // Denormalised from the version schema so the dashboard can
            // list/search forms without unpacking JSON.
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            // Pointer to the version served on the public URL. FK added in
            // the form_versions migration (table doesn't exist yet here).
            $table->unsignedBigInteger('published_version_id')->nullable();
            // Behavioural settings (accepting responses, success message,
            // limits) — kept out of the field schema on purpose.
            $table->json('settings')->nullable();
            // Denormalised counters so dashboards never aggregate raw events.
            $table->unsignedInteger('submissions_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Dashboard listing: "my forms, by status, newest first".
            $table->index(['user_id', 'status', 'updated_at']);
            // Dashboard search is a title prefix LIKE.
            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
