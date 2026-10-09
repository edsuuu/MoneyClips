<?php

declare(strict_types=1);

use App\Exceptions\ClaudeException;
use App\Models\Video;
use App\Services\API\Claude\ClaudeService;
use App\Services\CutSuggestion\ClaudeCutSuggestionService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function claudeResponse(array $overrides = []): string
{
    return (string) json_encode([
        'type' => 'result',
        'is_error' => false,
        'result' => '',
        'total_cost_usd' => 0.41,
        'usage' => ['input_tokens' => 1000, 'output_tokens' => 200],
        'structured_output' => ['candidates' => []],
        ...$overrides,
    ]);
}

it('runs claude -p without tools and returns the structured output', function (): void {
    Process::fake(['*' => Process::result(claudeResponse(['structured_output' => ['candidates' => [['start' => 1]]]]))]);

    $output = resolve(ClaudeService::class)->structured('prompt', '{}', 'entrada');

    expect($output)->toBe(['candidates' => [['start' => 1]]]);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (array) $process->command;

        return $process->input === 'entrada'
            && in_array('--safe-mode', $command, true)
            && in_array('--no-session-persistence', $command, true)
            && in_array('--json-schema', $command, true)
            && $command[array_search('--tools', $command, true) + 1] === '';
    });
});

it('fails loud when claude does not deliver', function (string $output, int $exitCode): void {
    Process::fake(['*' => Process::result($output, exitCode: $exitCode)]);

    resolve(ClaudeService::class)->structured('prompt', '{}', 'entrada');
})->throws(ClaudeException::class)->with([
    'limite atingido' => [claudeResponse(['is_error' => true, 'result' => 'Claude AI usage limit reached']), 1],
    'binário ausente' => ['', 127],
    'sem saída estruturada' => [claudeResponse(['structured_output' => null]), 0],
]);

it('maps the candidates and sends the transcript as timed lines', function (): void {
    Process::fake(['*' => Process::result(claudeResponse(['structured_output' => ['candidates' => [
        ['start' => 846.6, 'end' => 969.7, 'title' => 'MICHAEL JACKSON', 'first_line' => 'a', 'last_line' => 'b', 'arc' => 'setup -> soco', 'laughs' => 3, 'score' => 14, 'hashtags' => ['humor', '#podcast', 5]],
    ]]]))]);

    $suggestions = resolve(ClaudeCutSuggestionService::class)->suggest(
        new Video(['name' => 'Podpah', 'duration_seconds' => 2195]),
        ['segments' => [['start' => 846.62, 'end' => 848.7, 'text' => ' qual a comida favorita do Haaland?']]],
        '',
    );

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->start)->toBe(846.6)
        ->and($suggestions[0]->end)->toBe(969.7)
        ->and($suggestions[0]->score)->toBe(10)
        ->and($suggestions[0]->reason)->toBe('setup -> soco')
        ->and($suggestions[0]->title)->toBe('MICHAEL JACKSON')
        ->and($suggestions[0]->hashtags)->toBe(['#humor', '#podcast']);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->input, '[846.6-848.7] qual a comida favorita do Haaland?')
        && str_contains((string) $process->input, 'Pedido do dono: os momentos mais engraçados'));
});
