<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\SocialPost;
use App\Services\Posting\PostProviderInterface;
use App\Services\Posting\PostResultData;
use Closure;
use Throwable;

final class FakePostService implements PostProviderInterface
{
    /** @var list<array{post_id: int, local_path: string, file_existed: bool}> */
    public array $calls = [];

    public function __construct(
        private readonly PostResultData|Throwable $outcome,
        private readonly ?Closure $whileUploading = null,
    ) {}

    /**
     * @throws Throwable
     */
    public function post(SocialPost $post, string $localPath): PostResultData
    {
        $this->calls[] = ['post_id' => $post->id, 'local_path' => $localPath, 'file_existed' => is_file($localPath)];

        if (! is_null($this->whileUploading)) {
            ($this->whileUploading)($post);
        }

        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
