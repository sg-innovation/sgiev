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
    Route::get('settings/two-factor', [\App\Http\Controllers\Settings\TwoFactorController::class, 'edit'])->name('two-factor.edit');
    Route::post('settings/two-factor/setup', [\App\Http\Controllers\Settings\TwoFactorController::class, 'setup'])->middleware('throttle:10,1')->name('two-factor.setup');
    Route::post('settings/two-factor/confirm', [\App\Http\Controllers\Settings\TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::post('settings/two-factor/recovery-codes', [\App\Http\Controllers\Settings\TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:6,1')->name('two-factor.recovery-codes');
    Route::delete('settings/two-factor', [\App\Http\Controllers\Settings\TwoFactorController::class, 'destroy'])->middleware('throttle:6,1')->name('two-factor.destroy');
    Route::post('settings/profile/photo', [ProfileController::class, 'photo'])->middleware('throttle:10,1')->name('profile.photo');
    Route::delete('settings/profile/photo', [ProfileController::class, 'removePhoto'])->name('profile.photo.remove');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
    Route::post('settings/vehicles', [VehicleController::class, 'store'])->middleware('throttle:30,1')->name('vehicles.store');
    Route::put('settings/vehicles/{vehicle}', [VehicleController::class, 'update'])->middleware('throttle:30,1')->name('vehicles.update');
    Route::delete('settings/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->middleware('throttle:30,1')->name('vehicles.destroy');

    Route::get('settings/notifications', [\App\Http\Controllers\Settings\NotificationPreferencesController::class, 'edit'])->name('notifications.edit');
    Route::put('settings/notifications', [\App\Http\Controllers\Settings\NotificationPreferencesController::class, 'update'])->middleware('throttle:30,1')->name('notifications.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
