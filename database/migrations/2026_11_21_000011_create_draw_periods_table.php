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
        if (! Schema::hasTable('draw_periods')) {
            Schema::create('draw_periods', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('drawType');
                $table->string('drawDate');
                $table->bigInteger('salesOpenTime');
                $table->bigInteger('salesCloseTime');
                $table->bigInteger('drawTime');
                $table->string('status');
                $table->string('result')->nullable();
                $table->string('result3')->nullable();
                $table->string('result4')->nullable();
                $table->bigInteger('resultPublishedAt')->nullable();
                $table->bigInteger('result3PublishedAt')->nullable();
                $table->bigInteger('result4PublishedAt')->nullable();
                $table->string('publishedBy')->nullable();
                $table->string('result3PublishedBy')->nullable();
                $table->string('result4PublishedBy')->nullable();
                $table->string('resultSource')->nullable();
                $table->string('result3Source')->nullable();
                $table->string('result4Source')->nullable();
                $table->string('timezone')->nullable();

                $table->index('drawDate');
                $table->index('drawType');
                $table->index('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draw_periods');
    }
};
