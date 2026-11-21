<?php

namespace Database\Seeders;

use App\Services\DbService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeds accounts, games, draws, draw periods and default config —
        // the same data the Flutter app used to create on first launch.
        app(DbService::class)->bootstrap();
    }
}
