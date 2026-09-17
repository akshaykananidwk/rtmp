<?php

use App\Http\Controllers\Installer\InstallerController;
use App\Http\Middleware\EnsureNotInstalled;
use Illuminate\Support\Facades\Route;

Route::prefix('install')->middleware([EnsureNotInstalled::class, 'throttle:installer'])->name('install.')->group(function (): void {
    Route::get('/', [InstallerController::class, 'welcome'])->name('welcome');
    Route::get('/requirements', [InstallerController::class, 'requirements'])->name('requirements');
    Route::get('/database', [InstallerController::class, 'database'])->name('database');
    Route::post('/database/test', [InstallerController::class, 'testDatabase'])->name('database.test');
    Route::post('/database', [InstallerController::class, 'saveDatabase'])->name('database.save');
    Route::get('/application', [InstallerController::class, 'application'])->name('application');
    Route::post('/application', [InstallerController::class, 'saveApplication'])->name('application.save');
    Route::get('/admin', [InstallerController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallerController::class, 'saveAdmin'])->name('admin.save');
    Route::get('/run', [InstallerController::class, 'run'])->name('run');
    Route::post('/run', [InstallerController::class, 'execute'])->name('execute');
});
Route::get('/install/complete', [InstallerController::class, 'complete'])->name('install.complete');
