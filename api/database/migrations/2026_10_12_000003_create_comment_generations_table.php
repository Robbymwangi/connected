<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* Server bookkeeping for one request to the batch API, with id as the request's custom_id. Not
       synchronisable, like unlock_notes: submitting and polling never touch a comment, and applying a
       result is a separate, ordinary update of the comments row (docs/spec/workflow.md, Comments).
       No version and no soft delete; a superseded generation is a status, not a deletion.
       docs/spec/data-model.md. */
    public function up(): void
    {
        Schema::create('comment_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('comment_id')->constrained('comments');
            $table->string('batch_id')->nullable();
            $table->enum('status', ['submitted', 'succeeded', 'errored', 'expired', 'superseded']);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['comment_id', 'created_at']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_generations');
    }
};
