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
        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('serialNumber')->nullable();
                $table->string('gameId');
                $table->string('gameName');
                $table->string('drawId')->nullable();
                $table->string('drawName')->nullable();
                $table->string('numbers');
                $table->string('betType');
                $table->decimal('amount', 12, 2);
                $table->decimal('total', 12, 2);
                $table->string('operatorId');
                $table->string('operatorName');
                $table->bigInteger('createdAt');
                $table->string('status');
                $table->string('barcode')->nullable();
                $table->decimal('prizeAmount', 12, 2)->nullable();
                $table->bigInteger('paidAt')->nullable();
                $table->string('paidBy')->nullable();
                $table->integer('isQuickPick')->default(0);
                $table->bigInteger('drawDate')->nullable();
                $table->string('customerId')->nullable();
                $table->string('customerName')->nullable();

                $table->index('drawId');
                $table->index('status');
                $table->index('createdAt');
                $table->index('operatorId');
                $table->index('customerId');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
