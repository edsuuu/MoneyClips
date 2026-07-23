<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\VideoStatusEnum;
use App\Exceptions\TooManyOpenUploadsException;
use App\Exceptions\UploadFailedException;
use App\Exceptions\UploadRejectedException;
use App\Exceptions\UploadSessionClosedException;
use App\Http\Requests\Upload\CompleteUploadRequest;
use App\Http\Requests\Upload\SignUploadPartsRequest;
use App\Http\Requests\Upload\StoreUploadRequest;
use App\Http\Resources\SignedPartUrlsResource;
use App\Http\Resources\StatusResource;
use App\Http\Resources\UploadPartResource;
use App\Http\Resources\UploadSessionResource;
use App\Jobs\StartHLSPackagingJob;
use App\Models\File;
use App\Models\Video;
use App\Services\Upload\MultipartUploadInterface;
use App\Services\Upload\VideoSignatureService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class MultipartUploadController extends Controller
{
    /**
     * Cada sessao aberta e um multipart vivo no MinIO segurando espaco — o
     * prune so passa depois de 24h.
     */
    private const int MAX_OPEN_SESSIONS = 5;

    /**
     * @throws Throwable
     */
    public function store(StoreUploadRequest $request, MultipartUploadInterface $uploads): UploadSessionResource
    {
        $lock = Cache::lock('upload-start:'.Auth::id(), 120);

        // Non-blocking: dois POST /uploads concorrentes do mesmo usuário não
        // correm a checagem de MAX_OPEN nem abrem sessões duplicadas.
        throw_if(! $lock->get(), TooManyOpenUploadsException::class);

        try {
            $open = Video::query()
                ->where('user_id', Auth::id())
                ->where('status', VideoStatusEnum::AwaitingUpload)
                ->count();

            throw_if($open >= self::MAX_OPEN_SESSIONS, TooManyOpenUploadsException::class);

            $uuid = (string) Str::uuid();
            $key = Video::originalPathFor($uuid);
            $session = $uploads->create($key, $request->fileSize(), $request->mimeType());

            try {
                $video = DB::transaction(function () use ($uuid, $key, $session, $request): Video {
                    $video = Video::query()->create([
                        'user_id' => Auth::id(),
                        'uuid' => $uuid,
                        'status' => VideoStatusEnum::AwaitingUpload,
                    ]);

                    $video->files()->create([
                        'type' => File::ORIGINAL,
                        'path' => $key,
                        'upload_id' => $session->uploadId,
                        'size' => $request->fileSize(),
                        'mime_type' => $request->mimeType(),
                    ]);

                    return $video;
                });
            } catch (Throwable $exception) {
                // O banco falhou depois da sessão S3 abrir — não deixa multipart
                // órfão e devolve uma mensagem limpa (o desfecho vira toast no cliente).
                $uploads->abort($key, $session->uploadId);

                throw new UploadFailedException(message: $exception->getMessage(), code: $exception->getCode(), previous: $exception);
            }

            return new UploadSessionResource($video, $session);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws Throwable
     */
    public function parts(Video $video, MultipartUploadInterface $uploads): AnonymousResourceCollection
    {
        return UploadPartResource::collection(
            $uploads->listParts($video->originalPath(), $this->activeUploadId($video)),
        );
    }

    /**
     * @throws Throwable
     */
    public function sign(SignUploadPartsRequest $request, Video $video, MultipartUploadInterface $uploads): SignedPartUrlsResource
    {
        return new SignedPartUrlsResource(
            $uploads->signParts($video->originalPath(), $this->activeUploadId($video), $request->partNumbers()),
        );
    }

    /**
     * @throws UploadRejectedException
     * @throws Throwable
     */
    public function complete(
        CompleteUploadRequest $request,
        Video $video,
        MultipartUploadInterface $uploads,
        VideoSignatureService $signatures,
    ): StatusResource {
        $original = $this->originalFile($video);
        $uploadId = $this->activeUploadId($video);

        $uploads->complete($video->originalPath(), $uploadId, $request->parts());

        $actualSize = $uploads->size($video->originalPath());

        $header = $uploads->firstBytes($video->originalPath(), VideoSignatureService::HEADER_BYTES);

        if (! $signatures->looksLikeVideo($header)) {
            $this->reject(
                $video,
                'O conteúdo enviado não é um vídeo MP4, MOV ou WEBM.',
                'Formato não suportado — envie MP4, MOV ou WEBM.',
            );
        }

        $original->update(['size' => $actualSize, 'upload_id' => null]);
        $video->update(['status' => VideoStatusEnum::Uploaded]);

        dispatch(new StartHLSPackagingJob($video->id));

        return new StatusResource('uploaded', extra: ['video_uuid' => $video->uuid]);
    }

    private function reject(Video $video, string $reason, string $userMessage): never
    {
        // Não apaga o S3 — o binário fica (recuperável/auditável), só marca o
        // vídeo como recusado.
        $video->file(File::ORIGINAL)?->update(['upload_id' => null]);

        $video->fill([
            'status' => VideoStatusEnum::Rejected,
            'error' => $reason,
        ])->save();

        throw new UploadRejectedException($userMessage, $reason);
    }

    /**
     * @throws Throwable
     */
    private function originalFile(Video $video): File
    {
        $file = $video->file(File::ORIGINAL);

        throw_if(! $file instanceof File, UploadSessionClosedException::class);

        return $file;
    }

    /**
     * @throws Throwable
     */
    private function activeUploadId(Video $video): string
    {
        $uploadId = $this->originalFile($video)->upload_id;

        throw_if($uploadId === null, UploadSessionClosedException::class);

        return $uploadId;
    }
}
