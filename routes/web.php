<?php

use App\Livewire\ClaimAccount;
use App\Models\AndroidRelease;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing', ['androidRelease' => AndroidRelease::current()]);
});

Route::view('/privacy', 'privacy')->name('privacy');

// Sideloaded Android build while there is no Play Store listing; always the newest release.
Route::get('/android/download', \App\Http\Controllers\AndroidDownloadController::class)
    ->middleware('throttle:30,1')
    ->name('android.download');

Route::get('/claim', ClaimAccount::class)->name('claim');
Route::post('/locale', [\App\Http\Controllers\LocaleController::class, 'update'])->name('locale.update');

// spatie/laravel-passkeys route macro. NOT auto-registered by the package -
// the host app must opt in. Registers:
//   GET  /passkeys/authentication-options  [passkeys.authentication_options]
//   POST /passkeys/authenticate            [passkeys.login]
// The authenticate controller logs the user into the default web guard, so it
// coexists with Filament's password login (no config/auth.php change needed).
Route::passkeys();
