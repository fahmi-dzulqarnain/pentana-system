<?php

use App\Livewire\ClaimAccount;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

Route::view('/privacy', 'privacy')->name('privacy');

Route::get('/claim', ClaimAccount::class)->name('claim');
Route::post('/locale', [\App\Http\Controllers\LocaleController::class, 'update'])->name('locale.update');

// spatie/laravel-passkeys route macro. NOT auto-registered by the package -
// the host app must opt in. Registers:
//   GET  /passkeys/authentication-options  [passkeys.authentication_options]
//   POST /passkeys/authenticate            [passkeys.login]
// The authenticate controller logs the user into the default web guard, so it
// coexists with Filament's password login (no config/auth.php change needed).
Route::passkeys();
