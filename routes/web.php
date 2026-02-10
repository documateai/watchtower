<?php

use Illuminate\Support\Facades\Route;
use Documateai\Watchtower\Http\Controllers\DashboardController;
use Documateai\Watchtower\Http\Controllers\FailedJobsController;
use Documateai\Watchtower\Http\Controllers\JobsController;
use Documateai\Watchtower\Http\Controllers\MetricsController;
use Documateai\Watchtower\Http\Controllers\WorkersController;

/*
|--------------------------------------------------------------------------
| Watchtower Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the WatchtowerServiceProvider within a group
| that is assigned the "watchtower" middleware group and the configured
| path prefix.
|
*/

// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// API endpoint for polling updates
Route::get('/api/poll', [DashboardController::class, 'poll'])->name('api.poll');

// Jobs
Route::get('/jobs', [JobsController::class, 'index'])->name('jobs.index');
Route::get('/jobs/{id}', [JobsController::class, 'show'])->name('jobs.show');

// Failed Jobs
Route::get('/failed', [FailedJobsController::class, 'index'])->name('failed.index');
Route::post('/failed/{id}/retry', [FailedJobsController::class, 'retry'])->name('failed.retry');
Route::delete('/failed/{id}', [FailedJobsController::class, 'destroy'])->name('failed.destroy');

// Workers
Route::get('/workers', [WorkersController::class, 'index'])->name('workers.index');
Route::post('/workers/start', [WorkersController::class, 'start'])->name('workers.start');
Route::post('/workers/{id}/stop', [WorkersController::class, 'stop'])->name('workers.stop');
Route::post('/workers/{id}/pause', [WorkersController::class, 'pause'])->name('workers.pause');
Route::post('/workers/{id}/resume', [WorkersController::class, 'resume'])->name('workers.resume');

// Metrics
Route::get('/metrics', [MetricsController::class, 'index'])->name('metrics.index');
