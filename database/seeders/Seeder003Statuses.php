<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Status;
use Illuminate\Database\Seeder;

final class Seeder003Statuses extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['pending', 'Pendente'],
            ['queued', 'Na fila'],
            ['processing', 'Processando'],
            ['downloading', 'Baixando'],
            ['downloaded', 'Baixado'],
            ['transcribing', 'Transcrevendo'],
            ['transcript_confirmed', 'Transcrição confirmada'],
            ['subtitling_full', 'Legendando vídeo completo'],
            ['full_subtitled', 'Vídeo completo legendado'],
            ['waiting_cuts', 'Aguardando cortes'],
            ['recommending_cuts', 'Recomendando cortes'],
            ['cutting', 'Cortando'],
            ['completed', 'Concluído'],
            ['failed', 'Falhou'],
            ['cancelled', 'Cancelado'],
        ];

        foreach ($statuses as $index => [$key, $label]) {
            Status::query()->updateOrCreate(['key' => $key], ['label' => $label, 'sort_order' => $index + 1]);
        }
    }
}
