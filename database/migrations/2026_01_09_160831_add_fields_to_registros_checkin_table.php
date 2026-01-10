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
        Schema::table('registros_checkin', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedBigInteger('evento_id')->after('id');
            $table->unsignedBigInteger('grupo_id')->after('evento_id');
            $table->unsignedBigInteger('inmueble_base_id')->after('grupo_id');

            $table->string('estado', 20)->default('EN_PROCESO')->after('inmueble_base_id');

            // asistente
            $table->string('asistente_nombre')->nullable()->after('estado');
            $table->string('asistente_telefono', 30)->nullable()->after('asistente_nombre');
            $table->string('asistente_correo')->nullable()->after('asistente_telefono');

            // control
            $table->unsignedBigInteger('control_id')->nullable()->after('asistente_correo');

            // check-in
            $table->timestamp('checked_in_at')->nullable()->after('control_id');
            $table->unsignedBigInteger('checked_in_by_user_id')->nullable()->after('checked_in_at');
            $table->unsignedBigInteger('station_id')->nullable()->after('checked_in_by_user_id');

            // snapshots
            $table->decimal('coef_total_snapshot', 20, 10)->nullable()->after('station_id');
            $table->unsignedBigInteger('cabeza_inmueble_snapshot')->nullable()->after('coef_total_snapshot');
            $table->unsignedInteger('control_numero_snapshot')->nullable()->after('cabeza_inmueble_snapshot');

            // índices (candados útiles)
            $table->unique(['evento_id', 'inmueble_base_id'], 'ux_checkin_evento_inmueble');
            $table->index(['evento_id', 'estado'], 'ix_checkin_evento_estado');
            $table->index(['grupo_id'], 'ix_checkin_grupo');
        });
    }

    public function down(): void
    {
        Schema::table('registros_checkin', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropIndex('ix_checkin_grupo');
            $table->dropIndex('ix_checkin_evento_estado');
            $table->dropUnique('ux_checkin_evento_inmueble');

            $table->dropColumn([
                'evento_id',
                'grupo_id',
                'inmueble_base_id',
                'estado',
                'asistente_nombre',
                'asistente_telefono',
                'asistente_correo',
                'control_id',
                'checked_in_at',
                'checked_in_by_user_id',
                'station_id',
                'coef_total_snapshot',
                'cabeza_inmueble_snapshot',
                'control_numero_snapshot',
            ]);
        });
    }
};
