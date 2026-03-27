<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('registros_checkin', function (Blueprint $table) {
            $table->string('cabeza_inmueble_snapshot', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('registros_checkin', function (Blueprint $table) {
            $table->unsignedBigInteger('cabeza_inmueble_snapshot')->nullable()->change();
        });
    }
};