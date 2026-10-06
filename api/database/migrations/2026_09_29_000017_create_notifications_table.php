<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* Written by the server (a conflict raised, a report ready), read by a
       device by pull; unread is the one field a device pushes back, matching
       AGENTS.md's frontend rule that notifications are synced job-status
       rows, not push infrastructure. edit-blocked is a later, additive kind
       (docs/spec/workflow.md, #37): a mark edit rejected because its
       assessment was finalized before the edit arrived isn't the same fact
       as a sync-conflict between two people's values. tone matches
       components/StatusPill.tsx's StatusTone. docs/spec/data-model.md
       (#34). */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('user_id')->constrained('users');
            $table->enum('kind', ['sync-conflict', 'submission', 'report-ready', 'enrolment', 'edit-blocked']);
            $table->enum('tone', ['success', 'info', 'neutral', 'warning', 'danger', 'primary']);
            $table->string('title');
            $table->text('body');
            $table->boolean('unread')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
