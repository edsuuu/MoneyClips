<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @method void toast(string $message, string $variant = 'success')
 */
trait EditsTranscript
{
    private const int MAX_SEGMENT_CHARS = 1000;

    /**
     * Só o texto é editável — start/end/words vêm do S3 (autoritativo) e nunca
     * do cliente. Aplica text por índice; nada de apagar/adicionar segmento.
     *
     * @param  list<array{i?: mixed, text?: mixed}>  $edits
     */
    private function saveTranscriptText(string $key, array $edits): bool
    {
        $disk = Storage::disk('s3');

        try {
            if (! $disk->exists($key)) {
                $this->toast('Transcrição não encontrada.', 'danger');

                return false;
            }

            $data = json_decode((string) $disk->get($key), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($data)) {
                $this->toast('Transcrição inválida.', 'danger');

                return false;
            }

            $segments = is_array($data['segments'] ?? null) ? $data['segments'] : [];
            $count = count($segments);

            if ($count === 0 || count($edits) > $count) {
                $this->toast('Edição inválida.', 'danger');

                return false;
            }

            foreach ($edits as $edit) {
                $index = (int) ($edit['i'] ?? -1);
                $text = mb_trim((string) ($edit['text'] ?? ''));

                if ($index < 0 || $index >= $count || ! is_array($segments[$index])) {
                    $this->toast('Edição inválida.', 'danger');

                    return false;
                }

                if ($text === '' || mb_strlen($text) > self::MAX_SEGMENT_CHARS) {
                    $this->toast('Cada segmento precisa de um texto (não pode ficar vazio).', 'danger');

                    return false;
                }

                $segments[$index]['text'] = $text;
            }

            $data['segments'] = $segments;
            $disk->put($key, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível salvar as legendas. Tente de novo.', 'danger');

            return false;
        }

        $this->toast('Legendas salvas.');

        return true;
    }

    /** @return list<array{i: int, start: float, label: string, text: string}> */
    private function transcriptSegmentsFrom(string $key, bool $ready): array
    {
        if (! $ready) {
            return [];
        }

        $disk = Storage::disk('s3');

        try {
            if (! $disk->exists($key)) {
                return [];
            }

            $data = json_decode((string) $disk->get($key), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            report($throwable);

            return [];
        }

        $segments = is_array($data) && is_array($data['segments'] ?? null) ? $data['segments'] : [];

        $out = [];

        foreach (array_values($segments) as $index => $segment) {
            $start = is_array($segment) ? (float) ($segment['start'] ?? 0) : 0.0;

            $out[] = [
                'i' => $index,
                'start' => $start,
                'label' => sprintf('%d:%02d', intdiv((int) $start, 60), ((int) $start) % 60),
                'text' => is_array($segment) ? (string) ($segment['text'] ?? '') : '',
            ];
        }

        return $out;
    }
}
