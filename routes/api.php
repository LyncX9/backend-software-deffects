<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScanController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/scans', [ScanController::class, 'index']);
Route::post('/scans', [ScanController::class, 'store']);
Route::get('/scans/{scan}', [ScanController::class, 'show']);
Route::delete('/scans/{scan}', [ScanController::class, 'destroy']);
Route::get('/latency', [ScanController::class, 'getLatency']);