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
        Schema::table('controles', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedBigInteger('evento_id')->after('id');
            $table->unsignedInteger('numero')->after('evento_id');
            $table->string('serial', 50)->after('numero');
            $table->string('estado', 20)->default('LIBRE')->after('serial');
            $table->unsignedBigInteger('asignado_a_registro_id')->nullable()->after('estado');

            $table->unique(['evento_id', 'numero'], 'ux_controles_evento_numero');
            $table->index(['evento_id', 'estado'], 'ix_controles_evento_estado');
        });
    }

    public function down(): void
    {
        Schema::table('controles', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropIndex('ix_controles_evento_estado');
            $table->dropUnique('ux_controles_evento_numero');

            $table->dropColumn([
                'evento_id',
                'numero',
                'serial',
                'estado',
                'asignado_a_registro_id',
            ]);
        });
    }
};
