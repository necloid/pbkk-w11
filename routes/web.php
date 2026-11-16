<?php

use Illuminate\Support\Facades\Route;

// Konsol chat: login untuk mendapat token Sanctum, kirim prompt, lihat riwayat.
Route::view('/', 'console')->name('console');
