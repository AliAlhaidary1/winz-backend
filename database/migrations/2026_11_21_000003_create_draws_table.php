<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('draws')) {
            Schema::create('draws', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('gameId');
                $table->string('name');
                $table->bigInteger('drawTime');
                $table->string('status');
                $table->string('winningNumbers')->nullable();

                $table->index('gameId');
                $table->foreign('gameId')->references('id')->on('games');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draws');
    }
};
