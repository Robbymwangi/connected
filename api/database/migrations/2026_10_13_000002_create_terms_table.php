<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /* The term calendar (ADR 0014, decision 4), promoting docs/spec/data-model.md decision 3. A
       term has no planned dates: an admin opens it when the school has opened, and what is recorded
       is when that happened and who said so. At most one term is open per institution, and a term's
       identity is (institution_id, year, term) among live rows; a withdrawn term is soft deleted.
       Synchronisable and pull-only.

       The backfill turns every (year, term) an assessment already names into a term row that is
       ended, with no opened_at, since when it opened is not known: which term a school is in now is
       a fact only an admin can state, so the backfill opens none. It writes the change log as a
       model save would, so a device that already holds a cursor still pulls the new rows. */
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('term');
            $table->timestamp('opened_at')->nullable();
            $table->foreignUuid('opened_by')->nullable()->constrained('users');
            $table->timestamp('ended_at')->nullable();
            $table->foreignUuid('ended_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement('create unique index terms_live_unique on terms (institution_id, year, term) where deleted_at is null');
        DB::statement('create unique index terms_one_open on terms (institution_id) where ended_at is null and deleted_at is null');
        DB::statement('alter table terms add constraint terms_term_in_range check (term between 1 and 3)');
        DB::statement('alter table terms add constraint terms_year_positive check (year > 0)');
        DB::statement('alter table terms add constraint terms_ended_by_needs_ended_at check (ended_by is null or ended_at is not null)');

        // Ids from PHP, as every model generates them (HasUuidv7), not from the database's own uuidv7().
        $now = now();
        $named = DB::table('assessments')->select('institution_id', 'year', 'term')->distinct()
            ->orderBy('institution_id')->orderBy('year')->orderBy('term')->get();

        foreach ($named as $row) {
            DB::table('terms')->insert([
                'id' => (string) Str::uuid7(),
                'institution_id' => $row->institution_id,
                'year' => $row->year,
                'term' => $row->term,
                'ended_at' => $now,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::statement(<<<'SQL'
            insert into sync_changes (institution_id, "table", record_id, version, fields)
            select institution_id, 'terms', id, version, jsonb_build_object(
                'year', year, 'term', term, 'ended_at', to_char(ended_at, 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'))
            from terms
            order by institution_id, year, term
            SQL);
    }

    public function down(): void
    {
        DB::table('sync_changes')->where('table', 'terms')->delete();
        Schema::dropIfExists('terms');
    }
};
