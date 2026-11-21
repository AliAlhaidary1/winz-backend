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
        if (! Schema::hasTable('cash_sessions')) {
            Schema::create('cash_sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('operatorId');
                $table->decimal('openingAmount', 12, 2);
                $table->decimal('closingAmount', 12, 2)->nullable();
                $table->bigInteger('openedAt');
                $table->bigInteger('closedAt')->nullable();
                $table->string('status');

                $table->index('operatorId');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
