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
        Schema::create('evento_padron', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();

            $table->string('inmueble', 20); // 11407
            $table->string('propietario', 180);
            $table->decimal('coeficiente', 8, 4);

            $table->string('asistente', 180)->nullable();
            $table->string('celular_asistente', 30)->nullable();
            $table->string('correo_asistente', 180)->nullable();

            $table->timestamps();

            $table->unique(['evento_id', 'inmueble']);
            $table->index(['evento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_padron');
    }
};
