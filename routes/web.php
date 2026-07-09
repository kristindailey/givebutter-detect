<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/health')->name('home');

Route::get('/health', HealthController::class)->name('health');
