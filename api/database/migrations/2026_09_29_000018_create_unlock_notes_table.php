<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* An append-only audit log, not synchronisable in the full sense: written
       once by ADMIN, online, and a device only ever reads it as part of an
       assessment's history. No version, no soft delete, and only created_at,
       not the usual timestamps pair, since nothing should ever update a row
       here after it's written. docs/spec/data-model.md (#34), Conventions. */
    public function up(): void
    {
        Schema::create('unlock_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('assessment_id')->constrained('assessments');
            $table->foreignUuid('user_id')->constrained('users');
            $table->text('note');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unlock_notes');
    }
};
