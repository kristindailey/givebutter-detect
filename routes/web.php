<?php

use App\Http\Controllers\DuplicateController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/health')->name('home');

Route::get('/health', HealthController::class)->name('health');

// "Not a duplicate" — an Inertia action, deliberately not a JSON route, so the
// JSON surface stays exactly the two merge endpoints.
Route::post('/candidates/{candidate}/dismiss', [DuplicateController::class, 'dismiss'])->name('candidates.dismiss');
