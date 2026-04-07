<?php

use App\Http\Controllers\RunController;
use App\Http\Controllers\SseController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/workflows', [WorkflowController::class, 'index']);
Route::post('/runs', [RunController::class, 'store']);
Route::delete('/runs/{id}', [RunController::class, 'destroy']);
Route::get('/runs/{id}/stream', [SseController::class, 'stream']);
