<?php

declare(strict_types=1);

use App\Models\Video;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::view('/videos/create', 'videos.create')->name('videos.create');

Route::get('/videos/{video}/transcript', fn(Video $video) => view('videos.transcript', ['video' => $video]))->name('videos.transcript');

Route::get('/videos/{video}/editor', fn(Video $video) => view('videos.editor', ['video' => $video]))->name('videos.editor');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
