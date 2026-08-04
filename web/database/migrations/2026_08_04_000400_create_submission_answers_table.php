<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            // Denormalised so per-field analytics never join through
            // submissions ("answer distribution for field X of form Y").
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 100);
            $table->string('field_type', 30);
            // Scalar answers, and the search target for the submissions list.
            $table->mediumText('value_text')->nullable();
            // Structured answers: checkbox arrays, address objects, files.
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['submission_id', 'field_key']);
            $table->index(['form_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_answers');
    }
};
