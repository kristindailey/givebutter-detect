<?php

use App\Http\Controllers\DemoResetController;
use App\Http\Controllers\DuplicateController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// The Review Queue is the app's entry point.
Route::redirect('/', '/duplicates')->name('home');

Route::get('/duplicates', [DuplicateController::class, 'index'])->name('duplicates.index');

// Merge Review page. Stubbed by DuplicateController@show for now; the Merge Review
// feature builds out the diff + before/after panel behind this same route.
Route::get('/duplicates/{candidate}', [DuplicateController::class, 'show'])->name('duplicates.show');

Route::get('/health', HealthController::class)->name('health');

// "Not a duplicate" — an Inertia action, deliberately not a JSON route, so the
// JSON surface stays exactly the two merge endpoints.
Route::post('/candidates/{candidate}/dismiss', [DuplicateController::class, 'dismiss'])->name('candidates.dismiss');

// Restores the demo dataset on the deployed URL, where a visitor who merges the
// hero pair otherwise breaks the demo for the next one. Throttled because it's a
// destructive endpoint costing ~3s of database work, but loosely: the person most
// likely to hit the limit is whoever is driving a live demo, and a rate-limit
// error mid-demo is worse than the load it would have prevented.
Route::post('/demo/reset', DemoResetController::class)
    ->middleware('throttle:15,1')
    ->name('demo.reset');
