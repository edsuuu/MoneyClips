<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class ClientLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{level: string, message: string, request_id?: string|null, url?: string|null, context?: array<string, mixed>|null} $data */
        $data = $request->validate([
            'level' => ['required', 'in:warning,error'],
            'message' => ['required', 'string', 'max:2000'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:2000'],
            'context' => ['nullable', 'array'],
        ]);

        Log::log($data['level'], '[browser] '.$data['message'], [
            'request_id' => $data['request_id'] ?? null,
            'user_id' => Auth::id(),
            'url' => $data['url'] ?? null,
            'user_agent' => $request->userAgent(),
            'context' => $data['context'] ?? [],
        ]);

        return response()->json(['status' => 'logged']);
    }
}
