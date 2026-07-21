<?php

declare(strict_types=1);

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use App\Services\Upload\MultipartUploadInterface;
use Illuminate\Http\JsonResponse;
use Throwable;

final class AbortMultipartUploadController extends Controller
{
    public function __invoke(Video $video, MultipartUploadInterface $uploads): JsonResponse
    {

        if ($video->status !== VideoStatusEnum::AwaitingUpload) {
            return response()->json(['message' => 'Este upload já foi concluído.'], 409);
        }

        if ($video->upload_id !== null) {
            try {
                $uploads->abort($video->path(), $video->upload_id);
            } catch (Throwable) {
                // Upload já abortado/expirado no MinIO — a linha ainda precisa sair.
            }
        }

        $video->delete();

        return response()->json(['status' => 'aborted']);
    }
}
