<?php

use App\Http\Controllers\RunController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/workflows', [WorkflowController::class, 'index']);
Route::post('/runs', [RunController::class, 'store']);
