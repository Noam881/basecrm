<?php

use App\Http\Controllers\Auth\MagicLoginController;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::redirect('/', '/dashboard')->name('home');
require __DIR__.'/settings.php';

Route::get('/login', [MagicLoginController::class, 'create'])->name('login');
Route::post('/login', [MagicLoginController::class, 'store'])->middleware('throttle:magic-login-request')->name('login.store');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/magic-login', [MagicLoginController::class, 'create'])->name('magic-login.request');
Route::post('/magic-login', [MagicLoginController::class, 'store'])->middleware('throttle:magic-login-request')->name('magic-login.email');
Route::get('/magic-login/{token}', [MagicLoginController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:magic-login-consume')->name('magic-login.confirm');
Route::post('/magic-login/{token}', [MagicLoginController::class, 'consume'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:magic-login-consume')->name('magic-login.consume');

// Previously emailed links and bookmarks lead to the only supported sign-in screen.
Route::get('/forgot-password', RedirectController::class)->defaults('destination', '/login')->defaults('status', 302)->name('password.request');
Route::get('/reset-password/{token}', RedirectController::class)->defaults('destination', '/login')->defaults('status', 302)->name('password.reset');
Route::get('/reset-password', RedirectController::class)->defaults('destination', '/login')->defaults('status', 302);
Route::get('/user/confirm-password', RedirectController::class)->defaults('destination', '/login')->defaults('status', 302)->name('password.confirm');
Route::get('/user/confirmed-password-status', RedirectController::class)->defaults('destination', '/login')->defaults('status', 302)->name('password.confirmation');
Route::get('/two-factor-challenge', RedirectController::class)->defaults('destination', '/login')->defaults('status', 302)->name('two-factor.login');
Route::get('/email/verify', RedirectController::class)->defaults('destination', '/login')->defaults('status', 302)->name('verification.notice');
Route::get('/email/verify/{id}/{hash}', RedirectController::class)->defaults('destination', '/login')->defaults('status', 302)->name('verification.verify');
