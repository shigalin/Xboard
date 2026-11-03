<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_user_server_traffic', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('server_id');
            // Keep the last reported name even if the node is later deleted.
            $table->string('server_name');
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->bigInteger('billed_u')->default(0);
            $table->bigInteger('billed_d')->default(0);
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->unique(['user_id', 'server_id'], 'user_server_traffic_unique');
            $table->foreign('user_id')->references('id')->on('v2_user')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_server_traffic');
    }
};
