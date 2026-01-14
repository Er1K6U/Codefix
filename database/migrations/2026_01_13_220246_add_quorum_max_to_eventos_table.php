<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->decimal('quorum_max', 10, 4)->default(0)->after('quorum_objetivo');
            $table->timestamp('quorum_max_at')->nullable()->after('quorum_max');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn(['quorum_max', 'quorum_max_at']);
        });
    }
};
