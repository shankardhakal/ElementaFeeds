<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/feeds/cleanup', [\App\Http\Controllers\FeedController::class, 'cleanup'])->name('feeds.cleanup');
