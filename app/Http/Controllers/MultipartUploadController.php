<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\StartHLSPackagingJob;
use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use App\Services\Upload\MultipartUploadInterface;
use App\Services\Upload\MultipartUploadService;
use App\Services\Upload\UploadPartData;
use App\Services\Upload\VideoSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class MultipartUploadController extends Controller
{
    private const int MAX_WINDOW = 20;

    /**
     * Cada sessao aberta e um multipart vivo no MinIO segurando espaco — o
     * prune so passa depois de 24h.
     */
    private const int MAX_OPEN_SESSIONS = 5;

    public function store(Request $request, MultipartUploadInterface $uploads): JsonResponse
    {
        $open = Video::query()
            ->where('user_id', Auth::id())
            ->where('status', VideoStatusEnum::AwaitingUpload)
            ->count();

        if ($open >= self::MAX_OPEN_SESSIONS) {
            return response()->json([
                'message' => 'Você tem uploads demais em aberto. Conclua ou cancele antes de começar outro.',
            ], 429);
        }

        /** @var array{file_size: int, mime_type: string} $data */
        $data = $request->validate([
            'file_size' => ['required', 'integer', 'min:1', 'max:'.Video::MAX_BYTES],
            'mime_type' => ['required', 'string', 'in:'.implode(',', Video::MIME_TYPES)],
        ], [
            'file_size.max' => 'O vídeo passa do limite de 3GB.',
            'mime_type.in' => 'Formato não suportado — envie MP4, MOV ou WEBM.',
        ]);

        $uuid = (string) Str::uuid();

        $session = $uploads->create(Video::DIRECTORY.'/'.$uuid, $data['file_size'], $data['mime_type']);

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

    public function parts(Video $video, MultipartUploadInterface $uploads): JsonResponse
    {
        return response()->json([
            'parts' => array_map(
                fn (UploadPartData $part): array => [
                    'part_number' => $part->partNumber,
                    'etag' => $part->etag,
                ],
                $uploads->listParts($video->path(), $this->activeUploadId($video)),
            ),
        ]);
    }

    public function sign(Request $request, Video $video, MultipartUploadInterface $uploads): JsonResponse
    {
        $uploadId = $this->activeUploadId($video);

        /** @var array{part_numbers: list<int>} $data */
        $data = $request->validate([
            'part_numbers' => ['required', 'array', 'min:1', 'max:'.self::MAX_WINDOW],
            'part_numbers.*' => ['required', 'integer', 'min:1', 'max:'.MultipartUploadService::MAX_PARTS],
        ]);

        return response()->json([
            'urls' => $uploads->signParts($video->path(), $uploadId, $data['part_numbers']),
        ]);
    }

    public function complete(
        Request $request,
        Video $video,
        MultipartUploadInterface $uploads,
        VideoSignatureService $signatures,
    ): JsonResponse {
        $uploadId = $this->activeUploadId($video);

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

        $uploads->complete($video->path(), $uploadId, $parts);

        $actualSize = $uploads->size($video->path());

        if ($actualSize > Video::MAX_BYTES) {
            return $this->reject($video, 'O arquivo enviado passa do limite de 3GB.', 'O vídeo passa do limite de 3GB.');
        }

        $header = $uploads->firstBytes($video->path(), VideoSignatureService::HEADER_BYTES);

        if (! $signatures->looksLikeVideo($header)) {
            return $this->reject(
                $video,
                'O conteúdo enviado não é um vídeo MP4, MOV ou WEBM.',
                'Formato não suportado — envie MP4, MOV ou WEBM.',
            );
        }

        $video->fill([
            'file_size' => $actualSize,
            'status' => VideoStatusEnum::Uploaded,
            'upload_id' => null,
        ])->save();

        dispatch(new StartHLSPackagingJob($video->id));

        return response()->json(['status' => 'uploaded', 'video_uuid' => $video->uuid]);
    }

    public function destroy(Video $video, MultipartUploadInterface $uploads): JsonResponse
    {
        if ($video->status !== VideoStatusEnum::AwaitingUpload) {
            return response()->json(['message' => 'Este upload já foi concluído.'], 409);
        }

        if ($video->upload_id !== null) {
            try {
                $uploads->abort($video->path(), $video->upload_id);
            } catch (Throwable) {

            }
        }

        $video->delete();

        return response()->json(['status' => 'aborted']);
    }

    private function reject(Video $video, string $error, string $message): JsonResponse
    {
        Storage::disk('s3')->delete($video->path());

        $video->fill([
            'status' => VideoStatusEnum::Rejected,
            'upload_id' => null,
            'error' => $error,
        ])->save();

        return response()->json(['message' => $message], 422);
    }

    private function activeUploadId(Video $video): string
    {
        $uploadId = $video->upload_id;

        abort_if($uploadId === null, 409, 'Este upload já foi encerrado.');

        return $uploadId;
    }
}
