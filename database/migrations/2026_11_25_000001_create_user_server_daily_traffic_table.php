<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'v2_user_server_daily_traffic';
    private const UNIQUE = 'user_server_daily_traffic_unique';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->integer('user_id');
                $table->integer('server_id');
                $table->string('server_name');
                $table->integer('record_at')->index(); // Start of the UTC+8 report day.
                $table->bigInteger('u')->default(0);
                $table->bigInteger('d')->default(0);
                $table->bigInteger('billed_u')->default(0);
                $table->bigInteger('billed_d')->default(0);
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique(['user_id', 'record_at', 'server_id'], self::UNIQUE);
                $table->foreign('user_id')->references('id')->on('v2_user')->cascadeOnDelete();
            });
            return;
        }

        // Some databases commit CREATE TABLE and each index/constraint separately.
        $hasDayIndex = Schema::hasIndex(self::TABLE, ['record_at']);
        $hasUnique = Schema::hasIndex(self::TABLE, self::UNIQUE, 'unique');
        $hasUserKey = collect(Schema::getForeignKeys(self::TABLE))->contains(
            fn ($key) => $key['columns'] === ['user_id'] && $key['foreign_table'] === 'v2_user'
        );
        Schema::table(self::TABLE, function (Blueprint $table) use ($hasDayIndex, $hasUnique, $hasUserKey) {
            if (!$hasDayIndex) {
                $table->index('record_at');
            }
            if (!$hasUnique) {
                $table->unique(['user_id', 'record_at', 'server_id'], self::UNIQUE);
            }
            if (!$hasUserKey) {
                $table->foreign('user_id')->references('id')->on('v2_user')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
