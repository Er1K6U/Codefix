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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Contexto del evento auditado
            $table->string('modulo', 50);           // ej: eventos, registros
            $table->string('accion', 50);           // ej: created, updated, toggled
            $table->string('subject_type', 120)->nullable(); // App\Domain\Event\Models\Evento
            $table->unsignedBigInteger('subject_id')->nullable();

            // Quién hizo la acción
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Detalles adicionales
            $table->json('meta')->nullable();       // antes/después, observaciones
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            // Índices útiles
            $table->index(['modulo', 'accion']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
