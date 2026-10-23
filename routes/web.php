<?php

use App\Http\Controllers\Auth\MagicLoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');
require __DIR__.'/settings.php';

Route::get('/magic-login', [MagicLoginController::class, 'create'])->name('magic-login.request');
Route::post('/magic-login', [MagicLoginController::class, 'store'])->middleware('throttle:magic-login-request')->name('magic-login.email');
Route::get('/magic-login/{token}', [MagicLoginController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:magic-login-consume')->name('magic-login.confirm');
Route::post('/magic-login/{token}', [MagicLoginController::class, 'consume'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:magic-login-consume')->name('magic-login.consume');
