<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* One row per subject a user moderates. "Subject head" and "head of
       department" are titles over the same row shape (#36); a head of
       department moderating three subjects holds three rows.
       docs/spec/data-model.md (#34). */
    public function up(): void
    {
        Schema::create('subject_moderations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('subject_id')->constrained('subjects');
            $table->unsignedInteger('version')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        // Live-row-only, same reason as teacher_assignments: revoking then
        // re-granting a moderation must not collide with its own history.
        DB::statement(
            'create unique index subject_moderations_live_unique on subject_moderations '.
            '(user_id, subject_id) where deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_moderations');
    }
};
