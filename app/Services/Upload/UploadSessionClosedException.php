<?php

declare(strict_types=1);

namespace App\Services\Upload;

use Exception;
use Illuminate\Http\JsonResponse;

final class UploadSessionClosedException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => 'Este upload já foi encerrado.'], 409);
    }
}
