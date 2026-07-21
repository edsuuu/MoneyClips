<?php

declare(strict_types=1);

namespace App\Services\Upload;

use Exception;
use Illuminate\Http\JsonResponse;

final class TooManyOpenUploadsException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Você tem uploads demais em aberto. Conclua ou cancele antes de começar outro.',
        ], 429);
    }
}
