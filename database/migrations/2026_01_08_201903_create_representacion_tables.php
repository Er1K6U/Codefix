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
        Schema::create('representacion_grupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();

            // Cabeza actual del grupo (un registro del padrón)
            $table->foreignId('cabeza_padron_id')->constrained('evento_padron')->cascadeOnDelete();

            // Control asignado al grupo (se agrega después con FK a controles)
            $table->string('control_numero', 40)->nullable();
            $table->string('control_serial', 80)->nullable();

            // Check-in del grupo (luego lo usamos)
            $table->timestamp('checkin_at')->nullable();
            $table->foreignId('checkin_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['evento_id', 'cabeza_padron_id']);
            $table->index(['evento_id']);
        });

        Schema::create('representacion_miembros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('representacion_grupos')->cascadeOnDelete();
            $table->foreignId('padron_id')->constrained('evento_padron')->cascadeOnDelete();

            // true si es la cabeza (solo 1 por grupo)
            $table->boolean('es_cabeza')->default(false);

            $table->timestamps();

            // un inmueble no puede pertenecer a 2 grupos simultáneamente
            $table->unique(['padron_id']);

            // evita duplicados dentro del mismo grupo
            $table->unique(['grupo_id', 'padron_id']);

            $table->index(['grupo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('representacion_miembros');
        Schema::dropIfExists('representacion_grupos');
    }
};
