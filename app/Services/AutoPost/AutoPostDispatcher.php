<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\YoutubeShort;
use App\Services\AutoPost\Posters\PosterContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orquestrador fininho: por janela, sorteia N Shorts e roda cada Poster
 * habilitado contra eles. Idempotência da janela vive aqui (Cache::add);
 * estoque/reserva em StockReservation; agendamento em WindowSchedule;
 * detalhes por plataforma em cada Poster.
 */
final readonly class AutoPostDispatcher
{
    /**
     * @param  list<PosterContract>  $posters  Ordem importa só para logs (YT é síncrono e roda 1º).
     */
    public function __construct(
        private StockReservation $stock,
        private array $posters,
    ) {}

    /**
     * Sorteia/reserva N Shorts e publica cada um pelos posters habilitados.
     * Default de N: services.youtube_shorts.posting.posts_per_run (1).
     */
    public function run(?int $count = null): void
    {
        $enabled = array_values(array_filter($this->posters, static fn (PosterContract $p): bool => $p->isEnabled()));

        if ($enabled === []) {
            Log::warning('[AutoPost] Nenhuma plataforma habilitada — nada a postar.');

            return;
        }

        // Idempotência por janela: 1 execução por range mesmo com overlap.
        // Cache::add é atômico — só o 1º vencedor passa.
        $windowKey = WindowSchedule::windowKey();
        if ($windowKey === null) {
            Log::info('[AutoPost] Fora de qualquer janela ativa — nada a fazer.');

            return;
        }

        if (! Cache::add($windowKey, true, now()->addHours(6))) {
            Log::info('[AutoPost] Janela já processada — ignorando execução duplicada.', ['key' => $windowKey]);

            return;
        }

        $count = max(1, $count ?? (int) config('services.youtube_shorts.posting.posts_per_run', 1));

        for ($i = 0; $i < $count; $i++) {
            $short = $this->stock->reserveNext();
            if (! $short instanceof YoutubeShort) {
                Log::warning('[AutoPost] Nenhum Short disponível para postar.');
                break;
            }

            foreach ($enabled as $poster) {
                $poster->post($short);
            }
        }

        $this->stock->warnIfLowStock();
    }
}
