<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* Which term close a comments-failed or report-ready notice concerns, so a recipient gets one
       unread notice per kind and term close and the notice can link to it; it reaches a device as
       termClosureId. comments-failed joins the kinds. docs/spec/data-model.md, notifications. */
    private const KINDS = ['sync-conflict', 'submission', 'report-ready', 'enrolment', 'edit-blocked'];

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignUuid('term_closure_id')->nullable()->constrained('term_closures');
            $table->index(['user_id', 'kind', 'term_closure_id']);
        });

        $this->kinds([...self::KINDS, 'comments-failed']);
    }

    public function down(): void
    {
        DB::table('notifications')->where('kind', 'comments-failed')->delete();
        $this->kinds(self::KINDS);

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'kind', 'term_closure_id']);
            $table->dropConstrainedForeignId('term_closure_id');
        });
    }

    /** @param  array<int, string>  $kinds */
    private function kinds(array $kinds): void
    {
        $list = implode(', ', array_map(fn (string $kind) => "'{$kind}'", $kinds));

        DB::statement('alter table notifications drop constraint notifications_kind_check');
        DB::statement("alter table notifications add constraint notifications_kind_check check (kind in ({$list}))");
    }
};
