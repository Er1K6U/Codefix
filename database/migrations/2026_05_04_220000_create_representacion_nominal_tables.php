<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('representacion_grupos_nominal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();

            // Cabeza del grupo: una persona del padrón nominal
            $table->foreignId('cabeza_persona_id')->constrained('evento_personas')->cascadeOnDelete();

            // Control físico asignado (usado en check-in, Fase 4)
            $table->string('control_numero', 40)->nullable();
            $table->string('control_serial', 80)->nullable();

            // Check-in del grupo (Fase 4)
            $table->timestamp('checkin_at')->nullable();
            $table->foreignId('checkin_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['evento_id', 'cabeza_persona_id'], 'ux_grupos_nominal_evento_cabeza');
            $table->index(['evento_id'], 'ix_grupos_nominal_evento');
        });

        Schema::create('representacion_miembros_nominal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('representacion_grupos_nominal')->cascadeOnDelete();
            $table->unsignedBigInteger('evento_id');
            $table->foreignId('persona_id')->constrained('evento_personas')->cascadeOnDelete();

            // true si es la cabeza (solo 1 por grupo)
            $table->boolean('es_cabeza')->default(false);

            $table->timestamps();

            // Una persona solo puede estar en un grupo por evento
            $table->unique(['evento_id', 'persona_id'], 'ux_miembros_nominal_evento_persona');
            // Sin duplicados dentro del mismo grupo
            $table->unique(['grupo_id', 'persona_id'], 'ux_miembros_nominal_grupo_persona');

            $table->index(['grupo_id'], 'ix_miembros_nominal_grupo');
            $table->index(['evento_id'], 'ix_miembros_nominal_evento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('representacion_miembros_nominal');
        Schema::dropIfExists('representacion_grupos_nominal');
    }
};
