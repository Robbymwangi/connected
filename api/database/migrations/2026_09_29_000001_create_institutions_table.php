<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* The tenant boundary itself, not scoped by institution_id and not
       synchronisable: docs/spec/data-model.md (#34), Conventions. */
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
