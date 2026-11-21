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
        if (! Schema::hasTable('customer_plays')) {
            Schema::create('customer_plays', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('customerId');
                $table->string('gameId');
                $table->string('gameName');
                $table->string('numbers');
                $table->string('betType');
                $table->decimal('amount', 12, 2);
                $table->string('drawType')->nullable();
                $table->integer('isQuickPick')->default(0);
                $table->bigInteger('createdAt');

                $table->index('customerId');
                $table->index('createdAt');
                $table->foreign('customerId')->references('id')->on('customers');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_plays');
    }
};
