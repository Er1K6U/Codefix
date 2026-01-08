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
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();

            $table->string('titulo', 160);
            $table->string('slug', 180)->unique();
            $table->text('descripcion')->nullable();

            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();

            $table->boolean('activo')->default(true);

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['activo', 'fecha_inicio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eventos');
    }
};
