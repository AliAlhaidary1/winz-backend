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
        if (! Schema::hasTable('ticket_lines')) {
            Schema::create('ticket_lines', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('ticketId');
                $table->string('gameId');
                $table->string('gameName');
                $table->string('drawId')->nullable();
                $table->string('drawName')->nullable();
                $table->string('numbers');
                $table->string('betType');
                $table->decimal('amount', 12, 2);
                $table->integer('isQuickPick')->default(0);
                $table->string('periodId')->nullable();
                $table->string('periodStatus')->nullable();
                $table->string('winningStatus')->nullable();
                $table->decimal('prizeAmount', 12, 2)->nullable();

                $table->index('ticketId');
                $table->index('periodId');
                $table->foreign('ticketId')->references('id')->on('tickets');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_lines');
    }
};
