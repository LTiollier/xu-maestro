<?php

use App\Http\Controllers\EventsSseController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/workflows', [WorkflowController::class, 'index']);
Route::post('/workflows/generate', [WorkflowController::class, 'generate']);
Route::post('/workflows', [WorkflowController::class, 'store']);
Route::get('/runs', [RunController::class, 'index']);
Route::post('/runs', [RunController::class, 'store']);
Route::delete('/runs/{id}', [RunController::class, 'destroy']);
Route::get('/runs/{id}/logs', [RunController::class, 'logs']);
Route::get('/runs/{id}/events', [EventsSseController::class, 'stream']);
Route::post('/runs/{id}/retry-step', [RunController::class, 'retryStep']);
Route::post('/runs/{id}/answer', [RunController::class, 'answer']);
