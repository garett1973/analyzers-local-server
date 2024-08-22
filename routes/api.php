<?php

use App\Http\Controllers\ImportController;
use App\Http\Controllers\OrderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/import-order', [OrderController::class, 'importOrder']);
Route::post('/import-tests', [ImportController::class, 'importTests']);
Route::post('/import-analyzers', [ImportController::class, 'importAnalyzers']);
Route::post('/import-analyzer-types', [ImportController::class, 'importAnalyzerTypes']);
