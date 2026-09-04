<?php

use App\Http\Controllers\Admin\ChangeUserEmailController;
use App\Http\Controllers\Admin\OrganizationUserController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::post(
            '/organizations/{organization}/users',
            [OrganizationUserController::class, 'store']
        )->name('organizations.users.store');

        Route::patch(
            '/users/{user}',
            [OrganizationUserController::class, 'update']
        )->name('users.update');

        Route::patch(
            '/users/{user}/email',
            [ChangeUserEmailController::class, '__invoke']
        )->name('users.email.update');
    });

require __DIR__.'/auth.php';
