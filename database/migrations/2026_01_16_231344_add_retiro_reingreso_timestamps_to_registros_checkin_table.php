<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('registros_checkin', function (Blueprint $table) {
            $table->timestamp('retirado_at')->nullable()->after('checked_in_at');
            $table->unsignedBigInteger('retirado_by_user_id')->nullable()->after('retirado_at');

            $table->timestamp('reingreso_at')->nullable()->after('retirado_by_user_id');
            $table->unsignedBigInteger('reingreso_by_user_id')->nullable()->after('reingreso_at');

            // si quieres llaves foráneas después, lo hacemos en otro paso (por ahora cero riesgo)
        });
    }

    public function down(): void
    {
        Schema::table('registros_checkin', function (Blueprint $table) {
            $table->dropColumn([
                'retirado_at',
                'retirado_by_user_id',
                'reingreso_at',
                'reingreso_by_user_id',
            ]);
        });
    }
};
