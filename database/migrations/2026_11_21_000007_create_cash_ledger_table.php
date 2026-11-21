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
        if (! Schema::hasTable('cash_ledger')) {
            Schema::create('cash_ledger', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('type');
                $table->decimal('amount', 12, 2);
                $table->string('direction');
                $table->string('description')->nullable();
                $table->string('operatorId');
                $table->bigInteger('createdAt');
                $table->string('ticketId')->nullable();

                $table->index('operatorId');
                $table->index('createdAt');
                $table->index('ticketId');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_ledger');
    }
};
