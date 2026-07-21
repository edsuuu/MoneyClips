<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ClientLogRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class ClientLogController extends Controller
{
    public function __invoke(ClientLogRequest $request): JsonResponse
    {
        Log::log($request->level(), '[browser] '.$request->message(), [
            'request_id' => $request->requestId(),
            'user_id' => Auth::id(),
            'url' => $request->pageUrl(),
            'user_agent' => $request->userAgent(),
            'context' => $request->context(),
        ]);

        return response()->json(['status' => 'logged']);
    }
}
