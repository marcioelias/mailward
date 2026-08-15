<?php

declare(strict_types=1);

use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

/*
 * Nothing here is authenticated yet. The login gate is specified in
 * docs/features/authentication.md and is not implemented: it depends on
 * password scheme questions that are still open (docs/reference/decisions-needed.md).
 */
Route::get('/', StatusController::class)->name('status');
