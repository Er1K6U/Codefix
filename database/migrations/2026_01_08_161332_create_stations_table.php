<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique(); // IP del PC (red local)
            $table->string('nombre', 80)->nullable(); // "Puesto 1", "Mesa A", etc.
            $table->foreignId('active_event_id')
                ->nullable()
                ->constrained('eventos')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('active_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
