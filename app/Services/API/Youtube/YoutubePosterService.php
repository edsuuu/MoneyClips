<?php

declare(strict_types=1);

namespace App\Services\API\Youtube;

use App\Services\API\Discord\DiscordNotifierService;
use App\Services\AutoPost\PosterInterface;
use App\Services\AutoPost\PosterResultData;
use App\Services\AutoPost\PostTaskData;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class YoutubePosterService implements PosterInterface
{
    public function __construct(
        private ShortsPosterService $poster,
        private DiscordNotifierService $discord,
    ) {}

    public function platform(): string
    {
        return 'youtube';
    }

    public function post(PostTaskData $task): PosterResultData
    {
        $short = $task->short;

        try {
            $videoId = $this->poster->post($short);
            $link = 'https://www.youtube.com/shorts/'.$videoId;

            Log::info('[AutoPost][YouTube] Short postado.', ['id' => $short->id, 'link' => $link]);
            $this->discord->success(
                '✅ Short postado no YouTube',
                ($task->title ?: $short->youtube_id).PHP_EOL.$link,
                $link,
            );

            return PosterResultData::ok($this->platform(), $link);
        } catch (Throwable $throwable) {
            Log::error('[AutoPost][YouTube] Falha ao postar.', [
                'id' => $short->id,
                'error' => $throwable->getMessage(),
            ]);
            $this->discord->error(
                '❌ Falha ao postar Short no YouTube',
                'Short ID: '.$short->id.PHP_EOL.'Erro: '.$throwable->getMessage(),
            );

            return PosterResultData::failed($this->platform(), $throwable->getMessage());
        }
    }
}
