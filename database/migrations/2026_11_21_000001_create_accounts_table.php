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
        if (! Schema::hasTable('accounts')) {
            Schema::create('accounts', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('username')->unique();
                $table->string('password');
                $table->string('role');
                $table->string('status');
                $table->bigInteger('createdAt');
                $table->decimal('commission', 12, 2)->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
