<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('representacion_miembros', function (Blueprint $table) {
            // Eliminar unique global sobre padron_id (permitía un solo evento por inmueble)
            $table->dropUnique('representacion_miembros_padron_id_unique');

            // Eliminar el índice normal (evento_id, padron_id) antes de crear el unique equivalente
            $table->dropIndex('representacion_miembros_evento_id_padron_id_index');

            // Reemplazarlo por unique compuesto: un inmueble solo puede estar en un grupo por evento
            $table->unique(['evento_id', 'padron_id']);
        });
    }

    public function down(): void
    {
        Schema::table('representacion_miembros', function (Blueprint $table) {
            // Revertir: eliminar el unique compuesto
            $table->dropUnique('representacion_miembros_evento_id_padron_id_unique');

            // Restaurar el índice normal (evento_id, padron_id)
            $table->index(['evento_id', 'padron_id']);

            // Restaurar el unique global original sobre padron_id
            $table->unique(['padron_id']);
        });
    }
};
