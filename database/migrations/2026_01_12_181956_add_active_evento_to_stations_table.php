<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('active_evento_id')
                ->nullable()
                ->constrained('eventos')
                ->nullOnDelete();

            $table->timestamp('activated_at')->nullable();

            $table->foreignId('activated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activated_by');
            $table->dropColumn('activated_at');
            $table->dropConstrainedForeignId('active_evento_id');
        });
    }
};
