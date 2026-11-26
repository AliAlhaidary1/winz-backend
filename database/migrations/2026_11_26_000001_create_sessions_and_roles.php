<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('winz_sessions')) {
            Schema::create('winz_sessions', function (Blueprint $table): void {
                $table->string('token')->primary();
                $table->string('account_id');
                $table->bigInteger('created_at');
                $table->bigInteger('expires_at');
                $table->index(['account_id', 'expires_at']);
            });
        }

        // Normalize accounts to the four supported roles.
        DB::table('accounts')->where('role', 'admin')->update(['role' => 'owner']);
        DB::table('accounts')->whereIn('role', ['agent', 'cashier', 'worker'])->update(['role' => 'cashier']);
        DB::table('accounts')->where('username', 'admin')->update(['role' => 'owner']);
        DB::table('accounts')->where('username', 'worker1')->update(['role' => 'cashier']);
    }

    public function down(): void
    {
        Schema::dropIfExists('winz_sessions');
    }
};
