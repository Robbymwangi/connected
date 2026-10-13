<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* ADR 0014, decisions 1 and 2: every account is exactly one kind, a teacher (the app) or an
   admin (the panel), in place of the is_admin flag; and a teacher may hold the Director of
   Studies grant.

   Accounts keep their ids, so every reference to a user (marks, conflicts, finalizes, closes,
   comments, unlock notes) stays valid. An is_admin account becomes an admin in place. If one also
   holds anything that belongs to teaching, the migration refuses rather than choose a side for
   it: such a person is split into two accounts by hand first, the existing row keeping the side
   its history belongs to. An admin's device tokens are revoked, since an admin no longer signs in
   to the app. An assessment's creator counts as teaching: once an admin, they could no longer edit
   or finalize it, and no one else may unless assigned to its class and subject. */
return new class extends Migration
{
    public function up(): void
    {
        $admins = DB::table('users')->where('is_admin', true)->pluck('id');

        $teaching = $admins->filter(fn (string $id) => DB::table('teacher_assignments')->where('user_id', $id)->exists()
            || DB::table('subject_moderations')->where('user_id', $id)->exists()
            || DB::table('classes')->where('class_teacher_id', $id)->exists()
            || DB::table('marks')->where('last_edited_by', $id)->exists()
            || DB::table('assessments')->where('created_by', $id)->exists());

        if ($teaching->isNotEmpty()) {
            throw new RuntimeException(
                'These administrator accounts also teach; split each into an admin and a teacher account first (ADR 0014): '.$teaching->implode(', ')
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('kind')->default('teacher')->after('email');
            $table->boolean('directs_studies')->default(false)->after('kind');
        });

        DB::table('users')->whereIn('id', $admins)->update(['kind' => 'admin']);
        DB::table('personal_access_tokens')->where('tokenable_type', 'App\Models\User')->whereIn('tokenable_id', $admins)->delete();

        DB::statement("alter table users add constraint users_kind_check check (kind in ('teacher', 'admin'))");
        DB::statement('alter table users add constraint users_directs_studies_teacher_check check (not directs_studies or kind = \'teacher\')');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->unique(['kind', 'email']);
            $table->dropColumn('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false);
        });

        DB::table('users')->where('kind', 'admin')->update(['is_admin' => true]);

        DB::statement('alter table users drop constraint users_directs_studies_teacher_check');
        DB::statement('alter table users drop constraint users_kind_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['kind', 'email']);
            $table->unique('email');
            $table->dropColumn(['kind', 'directs_studies']);
        });
    }
};
