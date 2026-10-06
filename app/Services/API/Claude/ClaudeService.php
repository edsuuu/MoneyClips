<?php

declare(strict_types=1);

namespace App\Services\API\Claude;

use App\Exceptions\ClaudeException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JsonException;

final class ClaudeService
{
    /**
     * Uma chamada, sem ferramenta nenhuma: a entrada (transcrição do YouTube)
     * é conteúdo de terceiros, e com `--tools ""` a pior injeção possível é um
     * JSON ruim que o validador de quem chama barra.
     *
     * @return array<mixed>
     *
     * @throws ClaudeException
     * @throws JsonException
     */
    public function structured(string $promptFile, string $schemaFile, string $input): array
    {
        $command = [
            (string) config('services.claude.bin'), '-p',
            '--safe-mode',
            '--tools', '',
            '--no-session-persistence',
            '--model', (string) config('services.claude.model'),
            '--effort', (string) config('services.claude.effort'),
            '--system-prompt-file', resource_path($promptFile),
            '--output-format', 'json',
            '--json-schema', File::get(resource_path($schemaFile)),
        ];

        $timeout = (int) config('services.claude.timeout');

        try {
            $result = Process::timeout($timeout)->input($input)->run($command);
        } catch (ProcessTimedOutException $processTimedOutException) {
            Log::channel('claude')->error('[ERRO][Claude] claude -p estourou o timeout', [
                'command' => $command,
                'input' => $input,
                'exception' => $processTimedOutException,
            ]);

            throw ClaudeException::timedOut($timeout);
        }

        $output = $result->output();
        $response = json_validate($output) ? json_decode($output, true, 512, JSON_THROW_ON_ERROR) : null;

        $context = [
            'command' => $command,
            'input' => $input,
            'exit_code' => $result->exitCode(),
            'error_output' => $result->errorOutput(),
            'response' => $response ?? $output,
        ];

        if (! $result->successful() || ! is_array($response) || ($response['is_error'] ?? true) !== false) {
            Log::channel('claude')->error('[ERRO][Claude] claude -p falhou', $context);

            $detail = is_array($response) && is_string($response['result'] ?? null) ? $response['result'] : $result->errorOutput();

            throw ClaudeException::failed((int) $result->exitCode(), mb_trim($detail));
        }

        if (! is_array($response['structured_output'] ?? null)) {
            Log::channel('claude')->error('[ERRO][Claude] claude -p respondeu sem structured_output', $context);

            throw ClaudeException::missingStructuredOutput();
        }

        Log::channel('claude')->info('[INFO][Claude] chamada do claude -p', $context);

        return $response['structured_output'];
    }
}
