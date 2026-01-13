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
        Schema::table('registros_checkin', function (Blueprint $table) {
            $table->string('control_serial_snapshot', 50)->nullable()->after('control_numero_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('registros_checkin', function (Blueprint $table) {
            $table->dropColumn('control_serial_snapshot');
        });
    }

};
