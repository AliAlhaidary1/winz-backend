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
        if (! Schema::hasTable('games')) {
            Schema::create('games', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->string('type');
                $table->string('description')->nullable();
                $table->decimal('minAmount', 12, 2);
                $table->decimal('maxAmount', 12, 2);
                $table->integer('maxSelections');
                $table->integer('numberRangeMin');
                $table->integer('numberRangeMax');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
