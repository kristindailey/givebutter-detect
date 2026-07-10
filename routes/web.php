<?php

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
