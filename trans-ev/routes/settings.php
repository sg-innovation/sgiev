<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\VehicleController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
    Route::post('settings/vehicles', [VehicleController::class, 'store'])->middleware('throttle:30,1')->name('vehicles.store');
    Route::put('settings/vehicles/{vehicle}', [VehicleController::class, 'update'])->middleware('throttle:30,1')->name('vehicles.update');
    Route::delete('settings/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->middleware('throttle:30,1')->name('vehicles.destroy');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
