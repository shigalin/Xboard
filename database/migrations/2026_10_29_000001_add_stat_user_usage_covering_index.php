<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * stat/getUsage groups daily traffic and counts distinct users over a date
     * range. idx_stat_user_record_user covers the range scan but leaves
     * record_type, u and d to row lookups, so both aggregates read every row in
     * the range twice. Leading with record_type keeps the equality seek, and
     * carrying user_id, u and d makes the queries index-only.
     */
    public function up(): void
    {
        Schema::table('v2_stat_user', function (Blueprint $table) {
            $table->index(['record_type', 'record_at', 'user_id', 'u', 'd'], 'idx_stat_user_usage');
        });
    }

    public function down(): void
    {
        Schema::table('v2_stat_user', function (Blueprint $table) {
            $table->dropIndex('idx_stat_user_usage');
        });
    }
};
