<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('evento_personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();

            $table->string('cedula', 30);
            $table->string('nombre', 200);
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 180)->nullable();

            $table->timestamps();

            $table->unique(['evento_id', 'cedula'], 'ux_personas_evento_cedula');
            $table->index(['evento_id'], 'ix_personas_evento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evento_personas');
    }
};
