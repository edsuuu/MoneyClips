<?php

declare(strict_types=1);

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\Upload\MultipartUploadInterface;
use App\Services\Upload\MultipartUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assina as partes em janelas (não o upload inteiro de uma vez): num envio de
 * uma hora as URLs do fim expirariam antes da vez, e um retry precisa de
 * assinatura nova.
 */
final class SignUploadPartsController extends Controller
{
    private const int MAX_WINDOW = 20;

    public function __invoke(Request $request, Video $video, MultipartUploadInterface $uploads): JsonResponse
    {
        $this->authorize('update', $video);

        if ($video->upload_id === null) {
            return response()->json(['message' => 'Este upload já foi encerrado.'], 409);
        }

        /** @var array{part_numbers: list<int>} $data */
        $data = $request->validate([
            'part_numbers' => ['required', 'array', 'min:1', 'max:'.self::MAX_WINDOW],
            'part_numbers.*' => ['required', 'integer', 'min:1', 'max:'.MultipartUploadService::MAX_PARTS],
        ]);

        return response()->json([
            'urls' => $uploads->signParts($video->path(), $video->upload_id, $data['part_numbers']),
        ]);
    }
}
