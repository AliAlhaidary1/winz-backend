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
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('user');
                $table->string('action');
                $table->string('periodId')->nullable();
                $table->text('oldValue')->nullable();
                $table->text('newValue')->nullable();
                $table->bigInteger('timestamp');
                $table->string('reason')->nullable();

                $table->index('periodId');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
