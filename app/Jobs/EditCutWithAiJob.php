<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Exceptions\ClaudeException;
use App\Exceptions\WikipediaImageException;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use App\Services\API\Claude\ClaudeService;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\API\Wikipedia\WikipediaImageService;
use App\Services\CutEdit\CutEditAssetService;
use App\Services\CutEdit\CutEditValidatorService;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Segundo passo do "Editar com IA" (o webhook do face tracking despacha):
 * o Claude escreve o spec a partir das palavras do clip e dos turnos de
 * locutor, o validador confere, e o render sai direto pro estoque. Sem retry
 * da fila: cada tentativa queima o limite da assinatura.
 *
 * @phpstan-import-type ImageRequest from CutEditValidatorService
 * @phpstan-import-type ImageItem from CutEditValidatorService
 */
final class EditCutWithAiJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    /**
     * @param  list<array{start: float, end: float, speaker: int}>  $speakers
     */
    public function __construct(public int $editId, public array $speakers = [], public ?string $change = null)
    {
        $this->onQueue('processing');
    }

    /**
     * @throws ClaudeException
     * @throws FileNotFoundException
     * @throws JsonException
     * @throws Throwable
     */
    public function handle(ClaudeService $claude, CutEditValidatorService $validator, CutEditAssetService $stock, WikipediaImageService $wikipedia): void
    {
        $edit = VideoCutEdit::query()->with('videoCut.video')->find($this->editId);

        if (! $edit instanceof VideoCutEdit) {
            Log::channel('daily')->warning('[WARN][CutEditAi] Edição inexistente ao editar com IA.', ['id' => $this->editId]);

            return;
        }

        if ($edit->ai_status !== TranscriptionStatusEnum::Processing) {
            Log::channel('daily')->info('[INFO][CutEditAi] Edição fora do estado "processing" — ignorando.', [
                'id' => $edit->id,
                'ai_status' => $edit->ai_status?->value,
            ]);

            return;
        }

        $cut = $edit->videoCut;

        throw_unless($cut instanceof VideoCut, RuntimeException::class, 'O corte foi removido antes da edição com IA.');

        $segments = $this->wordSegments($cut);
        $words = array_merge(...$segments);

        throw_if($words === [], RuntimeException::class, 'A transcrição do corte não tem palavras com tempo.');

        $duration = (float) ($edit->source_meta['duration'] ?? $cut->end_seconds - $cut->start_seconds);
        $input = $this->input($edit, $segments, $duration);
        $captionPreset = $edit->caption_preset;

        if (! is_null($this->change)) {
            $input = $this->changeInput($input, $edit);
            $captionPreset = $edit->spec['caption_preset'] ?? $edit->caption_preset;
        }

        $assets = $stock->available($cut);
        $prompt = $stock->prompt($assets);
        $schema = $stock->schema($assets);

        $output = $claude->structured($prompt, $schema, $input);
        $result = $validator->validate($output, $words, $duration, $assets, $captionPreset);

        if ($result['hard'] === [] && $result['soft'] !== []) {
            Log::channel('daily')->info('[INFO][CutEditAi] Spec com erros moles — 1 retry.', ['edit_id' => $edit->id, 'soft' => $result['soft']]);

            $output = $claude->structured($prompt, $schema, $this->retryInput($input, $output, $result['soft']));
            $result = $validator->validate($output, $words, $duration, $assets, $captionPreset);
        }

        if ($result['warnings'] !== []) {
            Log::channel('daily')->warning('[WARN][CutEditAi] Figurinhas, imagens ou sons descartados.', ['edit_id' => $edit->id, 'warnings' => $result['warnings']]);
        }

        $errors = [...$result['hard'], ...$result['soft']];

        if ($errors !== []) {
            Log::channel('daily')->warning('[WARN][CutEditAi] IA não fechou a edição.', ['edit_id' => $edit->id, 'errors' => $errors]);

            VideoCutEdit::failAi($edit->id, implode('; ', $errors));

            return;
        }

        $spec = [...$result['spec'], 'images' => $this->resolveImages($result['spec']['images'], $wikipedia, $edit->id)];

        $claimed = VideoCutEdit::query()
            ->whereKey($edit->id)
            ->where('ai_status', TranscriptionStatusEnum::Processing->value)
            ->where(fn (Builder $query): Builder => $query->whereNull('render_status')->orWhere('render_status', '!=', VideoCutStatusEnum::Generating->value))
            ->update([
                'ai_status' => TranscriptionStatusEnum::Ready,
                'ai_error' => null,
                'spec' => json_encode([...$spec, 'ai_output' => $output], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'render_status' => VideoCutStatusEnum::Generating,
                'render_error' => null,
            ]);

        if ($claimed !== 1) {
            VideoCutEdit::failAi($edit->id, 'Um render manual começou durante a edição com IA — tente de novo quando ele terminar.');

            return;
        }

        dispatch(new StartVideoCutEditRenderJob($edit->id));

        Log::channel('daily')->info('[INFO][CutEditAi] Spec gravado — render disparado.', [
            'edit_id' => $edit->id,
            'captions' => count($result['spec']['captions']),
            'cuts' => count($result['spec']['cuts']),
            'punches' => count($result['spec']['punches']),
            'change' => $this->change,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida na edição com IA.';

        Log::channel('daily')->error('[ERRO][CutEditAi] Edição com IA falhou.', [
            'edit_id' => $this->editId,
            'exception' => $exception,
        ]);

        if (! VideoCutEdit::failAi($this->editId, $error)) {
            return;
        }

        resolve(DiscordNotifierService::class)->error(
            '❌ Edição com IA falhou',
            sprintf('Edição #%d%s%s', $this->editId, PHP_EOL, $error),
        );
    }

    /**
     * Imagem resolvida entra no render direto (a revisão é no estoque); a que
     * falha cai com log e o render segue: uma imagem não vale perder o spec
     * que custou as chamadas do Claude.
     *
     * @param  list<ImageRequest>  $images
     * @return list<ImageItem>
     */
    private function resolveImages(array $images, WikipediaImageService $wikipedia, int $editId): array
    {
        $resolved = [];

        foreach ($images as $image) {
            try {
                $asset = $wikipedia->resolve($image['title'], $image['lang']);
            } catch (WikipediaImageException $exception) {
                Log::channel('daily')->warning('[WARN][CutEditAi] Imagem da Wikipedia descartada.', ['edit_id' => $editId, 'exception' => $exception]);

                continue;
            } catch (Throwable $throwable) {
                Log::channel('daily')->error('[ERRO][CutEditAi] Falha ao resolver a imagem da Wikipedia — segue sem ela.', ['edit_id' => $editId, 'title' => $image['title'], 'exception' => $throwable]);

                continue;
            }

            $resolved[] = ['asset_id' => $asset->id, 'key' => $asset->storage_key, 'size' => $image['size'], 't' => $image['t'], 'credit' => $asset->credit()];
        }

        return $resolved;
    }

    /**
     * Palavras com tempo, agrupadas pelo segmento da transcrição do clip. O
     * índice que o Claude devolve é a posição na lista achatada.
     *
     * @return list<list<array{word: string, start: float, end: float}>>
     *
     * @throws JsonException
     */
    private function wordSegments(VideoCut $cut): array
    {
        $raw = Storage::disk('s3')->get($cut->transcriptPath());
        $transcript = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;

        throw_unless(is_array($transcript) && is_array($transcript['segments'] ?? null), RuntimeException::class, sprintf('Transcrição ilegível do corte #%d.', $cut->id));

        $segments = [];

        foreach ($transcript['segments'] as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            if (! is_array($segment['words'] ?? null)) {
                continue;
            }

            $words = [];

            foreach ($segment['words'] as $word) {
                if (! is_array($word)) {
                    continue;
                }

                if (! is_numeric($word['start'] ?? null)) {
                    continue;
                }

                if (! is_numeric($word['end'] ?? null)) {
                    continue;
                }

                $text = mb_trim((string) ($word['word'] ?? ''));

                if ($text === '') {
                    continue;
                }

                $words[] = ['word' => $text, 'start' => (float) $word['start'], 'end' => (float) $word['end']];
            }

            if ($words !== []) {
                $segments[] = $words;
            }
        }

        return $segments;
    }

    /**
     * @param  list<list<array{word: string, start: float, end: float}>>  $segments
     */
    private function input(VideoCutEdit $edit, array $segments, float $duration): string
    {
        $turns = [];

        foreach ($this->speakers as $speaker) {
            $turns[] = sprintf('[%.1f-%.1f] %d', $speaker['start'], $speaker['end'], $speaker['speaker']);
        }

        $lines = [
            sprintf('Vídeo: %s. Clip: %.1fs.', $edit->videoCut?->video->name ?? 'sem nome', $duration),
            'Pedido do dono: '.($edit->ai_request ?? 'nenhum'),
            'Locutores: '.($turns === [] ? 'sem dados' : implode(' | ', $turns)),
            'Palavras (índice|início|fim|palavra; linha em branco = troca de segmento):',
        ];

        $index = 0;

        foreach ($segments as $position => $words) {
            if ($position > 0) {
                $lines[] = '';
            }

            foreach ($words as $word) {
                $lines[] = sprintf('%d|%.2f|%.2f|%s', $index, $word['start'], $word['end'], $word['word']);
                $index++;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Refazer a partir do /meus-videos: só a última resposta da IA volta, sem
     * encadear o histórico, e o tracking não roda de novo.
     *
     * ponytail: os turnos de locutor não vão (`Locutores: sem dados`) — a
     * resposta anterior já carrega as decisões por quem fala. Ler o
     * speakers.json do corte se o refazer piorar notas e zooms.
     *
     * @throws JsonException
     */
    private function changeInput(string $input, VideoCutEdit $edit): string
    {
        return implode(PHP_EOL, [
            $input,
            '',
            'Edição anterior:',
            json_encode($edit->spec['ai_output'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            '',
            'Mudança pedida: '.$this->change,
        ]);
    }

    /**
     * @param  array<mixed>  $output
     * @param  list<string>  $soft
     *
     * @throws JsonException
     */
    private function retryInput(string $input, array $output, array $soft): string
    {
        return implode(PHP_EOL, [
            $input,
            '',
            'Sua resposta anterior:',
            json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            '',
            'Ela quebrou estas regras. Devolva o spec inteiro corrigindo só isto:',
            ...array_map(static fn (string $error): string => '- '.$error, $soft),
        ]);
    }
}
