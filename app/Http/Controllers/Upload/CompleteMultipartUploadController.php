<?php

declare(strict_types=1);

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Jobs\StartHLSPackagingJob;
use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use App\Services\Upload\MultipartUploadInterface;
use App\Services\Upload\MultipartUploadService;
use App\Services\Upload\UploadPartData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Fecha o multipart e confere o resultado contra o bucket. O tamanho declarado
 * no pré-flight veio do cliente; o do `headObject` é o que de fato foi gravado.
 */
final class CompleteMultipartUploadController extends Controller
{
    public function __invoke(Request $request, Video $video, MultipartUploadInterface $uploads): JsonResponse
    {

        if ($video->upload_id === null) {
            return response()->json(['message' => 'Este upload já foi encerrado.'], 409);
        }

        /** @var array{parts: list<array{part_number: int, etag: string}>} $data */
        $data = $request->validate([
            'parts' => ['required', 'array', 'min:1', 'max:'.MultipartUploadService::MAX_PARTS],
            'parts.*.part_number' => ['required', 'integer', 'min:1'],
            'parts.*.etag' => ['required', 'string', 'max:255'],
        ]);

        $parts = array_map(
            fn (array $part): UploadPartData => new UploadPartData($part['part_number'], $part['etag']),
            $data['parts'],
        );

        $uploads->complete($video->path(), $video->upload_id, $parts);

        $actualSize = $uploads->size($video->path());

        if ($actualSize > Video::MAX_BYTES) {
            Storage::disk('s3')->delete($video->path());
            $video->fill([
                'status' => VideoStatusEnum::Rejected,
                'upload_id' => null,
                'error' => 'O arquivo enviado passa do limite de 3GB.',
            ])->save();

            return response()->json(['message' => 'O vídeo passa do limite de 3GB.'], 422);
        }

        $video->fill([
            'file_size' => $actualSize,
            'status' => VideoStatusEnum::Uploaded,
            'upload_id' => null,
        ])->save();

        dispatch(new StartHLSPackagingJob($video->id));

        return response()->json(['status' => 'uploaded', 'video_uuid' => $video->uuid]);
    }
}
