<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('representacion_miembros', function (Blueprint $table) {
            // 1) Crear índice normal sobre padron_id con nombre explícito.
            //    La FK representacion_miembros_padron_id_foreign necesita un índice
            //    con padron_id en primera posición; el unique(evento_id, padron_id)
            //    no lo satisface porque padron_id queda en segunda posición.
            $table->index(['padron_id'], 'representacion_miembros_padron_id_index');

            // 2) Ahora MySQL permite eliminar el unique global sobre padron_id
            //    porque la FK ya tiene soporte en el índice del paso anterior.
            $table->dropUnique('representacion_miembros_padron_id_unique');

            // 3) Eliminar el índice normal (evento_id, padron_id) que será
            //    reemplazado por el unique compuesto del paso siguiente.
            $table->dropIndex('representacion_miembros_evento_id_padron_id_index');

            // 4) Crear unique compuesto: un inmueble solo puede estar en un grupo por evento.
            //    El índice normal de padron_id del paso 1 queda vivo para soportar la FK.
            $table->unique(['evento_id', 'padron_id']);
        });
    }

    public function down(): void
    {
        Schema::table('representacion_miembros', function (Blueprint $table) {
            // 1) Eliminar el unique compuesto creado en up().
            $table->dropUnique('representacion_miembros_evento_id_padron_id_unique');

            // 2) Restaurar el índice normal (evento_id, padron_id).
            $table->index(['evento_id', 'padron_id']);

            // 3) Restaurar el unique global sobre padron_id.
            //    Posible porque representacion_miembros_padron_id_index (del up) sigue vivo.
            $table->unique(['padron_id']);

            // 4) Eliminar el índice normal de padron_id agregado en up().
            //    El unique(padron_id) del paso anterior ya da soporte suficiente a la FK.
            $table->dropIndex('representacion_miembros_padron_id_index');
        });
    }
};
