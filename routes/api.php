<?php

declare(strict_types=1);

use App\Http\Controllers\VideoProcessorCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/video-processor/callbacks', VideoProcessorCallbackController::class)
    ->name('video-processor.callbacks');
