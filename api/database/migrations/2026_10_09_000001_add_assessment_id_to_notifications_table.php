<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* Which assessment a notification concerns, so a notice can link to it and, more to the point,
       so the server can avoid writing the same notice again and again: a recipient gets one unread
       notice per kind and assessment, not one per cell (docs/spec/data-model.md, notifications;
       docs/spec/sync-protocol.md). Nullable, because most kinds (a report ready, an enrolment) do not
       concern an assessment. It reaches a device through pull as `assessmentId`. The index serves the
       "already has an unread one" lookup. Additive: no existing migration is edited. */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignUuid('assessment_id')->nullable()->constrained('assessments');
            $table->index(['user_id', 'kind', 'assessment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'kind', 'assessment_id']);
            $table->dropConstrainedForeignId('assessment_id');
        });
    }
};
