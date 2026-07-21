<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Upload\CompleteUploadRequest;
use App\Http\Requests\Upload\SignUploadPartsRequest;
use App\Http\Requests\Upload\StoreUploadRequest;
use App\Http\Resources\SignedPartUrlsResource;
use App\Http\Resources\StatusResource;
use App\Http\Resources\UploadPartResource;
use App\Http\Resources\UploadSessionResource;
use App\Jobs\StartHLSPackagingJob;
use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use App\Services\Upload\MultipartUploadInterface;
use App\Services\Upload\TooManyOpenUploadsException;
use App\Services\Upload\UploadAlreadyCompletedException;
use App\Services\Upload\UploadRejectedException;
use App\Services\Upload\UploadSessionClosedException;
use App\Services\Upload\VideoSignatureService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class MultipartUploadController extends Controller
{
    /**
     * Cada sessao aberta e um multipart vivo no MinIO segurando espaco — o
     * prune so passa depois de 24h.
     */
    private const int MAX_OPEN_SESSIONS = 5;

    public function store(StoreUploadRequest $request, MultipartUploadInterface $uploads): UploadSessionResource
    {
        $open = Video::query()
            ->where('user_id', Auth::id())
            ->where('status', VideoStatusEnum::AwaitingUpload)
            ->count();

        throw_if($open >= self::MAX_OPEN_SESSIONS, TooManyOpenUploadsException::class);

        $uuid = (string) Str::uuid();

        $session = $uploads->create(Video::DIRECTORY.'/'.$uuid, $request->fileSize(), $request->mimeType());

        $video = Video::query()->create([
            'user_id' => Auth::id(),
            'uuid' => $uuid,
            'file_size' => $request->fileSize(),
            'mime_type' => $request->mimeType(),
            'status' => VideoStatusEnum::AwaitingUpload,
            'upload_id' => $session->uploadId,
        ]);

        return new UploadSessionResource($video, $session);
    }

    public function parts(Video $video, MultipartUploadInterface $uploads): AnonymousResourceCollection
    {
        return UploadPartResource::collection(
            $uploads->listParts($video->path(), $this->activeUploadId($video)),
        );
    }

    public function sign(SignUploadPartsRequest $request, Video $video, MultipartUploadInterface $uploads): SignedPartUrlsResource
    {
        return new SignedPartUrlsResource(
            $uploads->signParts($video->path(), $this->activeUploadId($video), $request->partNumbers()),
        );
    }

    public function complete(
        CompleteUploadRequest $request,
        Video $video,
        MultipartUploadInterface $uploads,
        VideoSignatureService $signatures,
    ): StatusResource {
        $uploadId = $this->activeUploadId($video);

        $uploads->complete($video->path(), $uploadId, $request->parts());

        $actualSize = $uploads->size($video->path());

        if ($actualSize > Video::MAX_BYTES) {
            $this->reject($video, 'O arquivo enviado passa do limite de 3GB.', 'O vídeo passa do limite de 3GB.');
        }

        $header = $uploads->firstBytes($video->path(), VideoSignatureService::HEADER_BYTES);

        if (! $signatures->looksLikeVideo($header)) {
            $this->reject(
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

        return new StatusResource('uploaded', extra: ['video_uuid' => $video->uuid]);
    }

    public function destroy(Video $video, MultipartUploadInterface $uploads): StatusResource
    {
        throw_if($video->status !== VideoStatusEnum::AwaitingUpload, UploadAlreadyCompletedException::class);

        if ($video->upload_id !== null) {
            try {
                $uploads->abort($video->path(), $video->upload_id);
            } catch (Throwable) {

            }
        }

        $video->delete();

        return new StatusResource('aborted');
    }

    private function reject(Video $video, string $reason, string $userMessage): never
    {
        Storage::disk('s3')->delete($video->path());

        $video->fill([
            'status' => VideoStatusEnum::Rejected,
            'upload_id' => null,
            'error' => $reason,
        ])->save();

        throw new UploadRejectedException($userMessage, $reason);
    }

    private function activeUploadId(Video $video): string
    {
        $uploadId = $video->upload_id;

        throw_if($uploadId === null, UploadSessionClosedException::class);

        return $uploadId;
    }
}
