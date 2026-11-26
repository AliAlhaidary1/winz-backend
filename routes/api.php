<?php

declare(strict_types=1);

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['ok' => true, 'result' => ['status' => 'ok']]);
});

Route::post('/call', [ApiController::class, 'call'])->middleware('api.token');

Route::post('/restore', [ApiController::class, 'restore'])->middleware('api.token');
