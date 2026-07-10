<?php

use App\Http\Controllers\MergeController;
use Illuminate\Support\Facades\Route;

/*
 * The deliberate JSON surface: exactly two merge actions. A dry-run GET and a
 * committing POST sharing one MergeService projection is where the API design is
 * the artifact an async reviewer reads. Everything else rides Inertia props.
 *
 * Registered under the `web` middleware group (see bootstrap/app.php) so these
 * ride the shared session — no CORS, no bearer plumbing.
 */
Route::get('/api/contacts/merge-preview', [MergeController::class, 'preview'])->name('contacts.merge-preview');
Route::post('/api/contacts/merge', [MergeController::class, 'commit'])->name('contacts.merge');
