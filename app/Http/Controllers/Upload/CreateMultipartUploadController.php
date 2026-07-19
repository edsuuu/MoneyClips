<?php

declare(strict_types=1);

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use App\Services\Upload\MultipartUploadInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Pré-flight do upload: valida o que o cliente declara e, só então, emite o
 * voucher multipart. Reprovando aqui não existe upload para rejeitar depois.
 */
final class CreateMultipartUploadController extends Controller
{
    public function __invoke(Request $request, MultipartUploadInterface $uploads): JsonResponse
    {
        /** @var array{file_size: int, mime_type: string} $data */
        $data = $request->validate([
            'file_size' => ['required', 'integer', 'min:1', 'max:'.Video::MAX_BYTES],
            'mime_type' => ['required', 'string', 'in:'.implode(',', Video::MIME_TYPES)],
        ], [
            'file_size.max' => 'O vídeo passa do limite de 3GB.',
            'mime_type.in' => 'Formato não suportado — envie MP4, MOV ou WEBM.',
        ]);

        $uuid = (string) Str::uuid();
        $key = Video::DIRECTORY.'/'.$uuid;

        $session = $uploads->create($key, $data['file_size'], $data['mime_type']);

        $video = Video::query()->create([
            'user_id' => Auth::id(),
            'uuid' => $uuid,
            'file_size' => $data['file_size'],
            'mime_type' => $data['mime_type'],
            'status' => VideoStatusEnum::AwaitingUpload,
            'upload_id' => $session->uploadId,
        ]);

        return response()->json([
            'video_uuid' => $video->uuid,
            ...$session->toArray(),
        ], 201);
    }
}
