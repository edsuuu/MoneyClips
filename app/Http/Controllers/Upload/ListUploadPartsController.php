<?php

declare(strict_types=1);

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\Upload\MultipartUploadInterface;
use App\Services\Upload\UploadPartData;
use Illuminate\Http\JsonResponse;

/**
 * Partes já gravadas, para o browser retomar de onde parou.
 */
final class ListUploadPartsController extends Controller
{
    public function __invoke(Video $video, MultipartUploadInterface $uploads): JsonResponse
    {
        $this->authorize('update', $video);

        if ($video->upload_id === null) {
            return response()->json(['message' => 'Este upload já foi encerrado.'], 409);
        }

        return response()->json([
            'parts' => array_map(
                fn (UploadPartData $part): array => [
                    'part_number' => $part->partNumber,
                    'etag' => $part->etag,
                ],
                $uploads->listParts($video->path(), $video->upload_id),
            ),
        ]);
    }
}
