<?php

use App\Http\Controllers\DiscoveryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DiscoveryController::class, 'index'])->name('dashboard');
Route::post('/worker/start', [DiscoveryController::class, 'startWorker'])->middleware('throttle:6,1')->name('worker.start');
Route::get('/sites/create', [DiscoveryController::class, 'create'])->name('sites.create');
Route::post('/sites', [DiscoveryController::class, 'store'])->name('sites.store');
Route::get('/sites/{site}/edit', [DiscoveryController::class, 'edit'])->name('sites.edit');
Route::put('/sites/{site}', [DiscoveryController::class, 'update'])->name('sites.update');
Route::post('/sites/{site}/scan', [DiscoveryController::class, 'start'])->name('sites.scan');
Route::get('/scans/{scan}', [DiscoveryController::class, 'show'])->name('scans.show');
Route::get('/scans/{scan}/delete', [DiscoveryController::class, 'confirmDestroy'])->name('scans.delete');
Route::delete('/scans/{scan}', [DiscoveryController::class, 'destroy'])->name('scans.destroy');
Route::get('/scans/{scan}/export', [DiscoveryController::class, 'export'])->name('scans.export');
Route::post('/scans/{scan}/cancel', [DiscoveryController::class, 'cancel'])->name('scans.cancel');
Route::post('/scans/{scan}/resume', [DiscoveryController::class, 'resume'])->name('scans.resume');
