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
        if (! Schema::hasTable('app_config')) {
            Schema::create('app_config', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value');
                $table->string('scope');
                $table->bigInteger('updatedAt');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_config');
    }
};
