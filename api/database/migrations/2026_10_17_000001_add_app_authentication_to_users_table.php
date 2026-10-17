<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Admin MFA in the panel (ADR 0014, "Panel MFA, decided for deployment"): Filament's app
   authentication keeps a TOTP secret and recovery codes on the account, both encrypted by the model
   casts. Additive and nullable, so no existing row or device is affected; both are hidden
   attributes, so neither ever enters the sync log (SyncLog::fieldsFor). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};