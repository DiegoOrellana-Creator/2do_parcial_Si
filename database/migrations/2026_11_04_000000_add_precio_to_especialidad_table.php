<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precio de la consulta por especialidad. Es opcional para no alterar las especialidades
     * existentes: quedan sin precio hasta que se les asigne uno.
     */
    public function up(): void
    {
        Schema::table('especialidad', function (Blueprint $table) {
            $table->decimal('precio', 10, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('especialidad', function (Blueprint $table) {
            $table->dropColumn('precio');
        });
    }
};
