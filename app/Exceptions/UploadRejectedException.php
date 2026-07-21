<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

final class UploadRejectedException extends Exception
{
    public function __construct(private readonly string $userMessage, string $reason)
    {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->getMessage();
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->userMessage], 422);
    }
}
