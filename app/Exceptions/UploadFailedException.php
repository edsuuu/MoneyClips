<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

final class UploadFailedException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Não foi possível iniciar o envio. Tente novamente.',
        ], 503);
    }
}
