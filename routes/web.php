<?php

use App\Livewire\ClaimAccount;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/claim', ClaimAccount::class)->name('claim');
