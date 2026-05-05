<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registros_checkin', function (Blueprint $table) {
            // FK a persona nominal (NULL en registros de modo coeficiente)
            $table->foreignId('persona_id')
                ->nullable()
                ->constrained('evento_personas')
                ->nullOnDelete()
                ->after('inmueble_base_id');

            // Relajar NOT NULL para soportar registros nominales
            $table->unsignedBigInteger('inmueble_base_id')->nullable()->change();
            $table->unsignedBigInteger('grupo_id')->nullable()->change();

            // Unicidad nominal: una persona solo puede tener 1 check-in por evento
            // MySQL trata NULLs como distintos en índices únicos,
            // por lo que los registros coeficiente (persona_id = NULL) no colisionan.
            $table->unique(['evento_id', 'persona_id'], 'ux_checkin_evento_persona');
        });
    }

    public function down(): void
    {
        Schema::table('registros_checkin', function (Blueprint $table) {
            $table->dropUnique('ux_checkin_evento_persona');
            $table->dropForeign(['persona_id']);
            $table->dropColumn('persona_id');

            $table->unsignedBigInteger('inmueble_base_id')->nullable(false)->change();
            $table->unsignedBigInteger('grupo_id')->nullable(false)->change();
        });
    }
};
