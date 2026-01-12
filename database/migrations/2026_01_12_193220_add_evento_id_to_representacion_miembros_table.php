<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('representacion_miembros', function (Blueprint $table) {
            $table->unsignedBigInteger('evento_id')->nullable()->after('grupo_id');
            $table->index(['evento_id', 'padron_id']);
            $table->index(['evento_id', 'grupo_id']);
        });

        // Backfill: tomar evento_id desde el grupo
        DB::statement("
            UPDATE representacion_miembros rm
            JOIN representacion_grupos rg ON rg.id = rm.grupo_id
            SET rm.evento_id = rg.evento_id
            WHERE rm.evento_id IS NULL
        ");

        // Ya con datos, lo hacemos NOT NULL
        Schema::table('representacion_miembros', function (Blueprint $table) {
            $table->unsignedBigInteger('evento_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('representacion_miembros', function (Blueprint $table) {
            $table->dropIndex(['evento_id', 'padron_id']);
            $table->dropIndex(['evento_id', 'grupo_id']);
            $table->dropColumn('evento_id');
        });
    }
};
