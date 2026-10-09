<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Exceptions\ClaudeException;
use App\Jobs\EditCutWithAiJob;
use App\Jobs\StartFaceTrackingJob;
use App\Jobs\StartVideoCutEditRenderJob;
use App\Livewire\Uploads\Show;
use App\Livewire\VideoEditor\Index as VideoEditor;
use App\Livewire\Videos\Index as Videos;
use App\Models\StockAsset;
use App\Models\Video;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use App\Models\YoutubeShort;
use Illuminate\Http\Client\Request;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('s3');
    config(['services.observability.token' => 'test-token']);
    $this->fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/spec-17-sovaco-peixe.json'), true, 512, JSON_THROW_ON_ERROR);
});

function aiEditCut(array $fixture, array $attributes = []): VideoCut
{
    $video = Video::factory()->ready()->create(['name' => 'Bate ou Regaça | Programa Pânico']);
    $cut = $video->cuts()->create([
        'start_seconds' => 116,
        'end_seconds' => 212,
        'status' => VideoCutStatusEnum::Ready,
        'transcription_status' => TranscriptionStatusEnum::Ready,
        ...$attributes,
    ]);

    Storage::disk('s3')->put($cut->transcriptPath(), (string) json_encode([
        'segments' => [['start' => 0.0, 'end' => 96.0, 'text' => 'transcrição', 'words' => $fixture['words']]],
    ]));

    return $cut;
}

function aiEditFor(VideoCut $cut, array $attributes = []): VideoCutEdit
{
    return VideoCutEdit::factory()->create([
        'video_cut_id' => $cut->id,
        'source_meta' => ['width' => 1920, 'height' => 1080, 'duration' => 96.0],
        'ai_status' => TranscriptionStatusEnum::Processing,
        'ai_request' => 'o loiro é o Castanhari',
        'tracking_status' => TranscriptionStatusEnum::Ready,
        ...$attributes,
    ]);
}

function claudeEditResult(array $structuredOutput, bool $isError = false): FakeProcessResult
{
    return Process::result((string) json_encode([
        'type' => 'result',
        'is_error' => $isError,
        'result' => $isError ? 'Claude AI usage limit reached' : '',
        'total_cost_usd' => 0.3,
        'structured_output' => $isError ? null : $structuredOutput,
    ]), exitCode: $isError ? 1 : 0);
}

function runEditCutWithAi(VideoCutEdit $edit): void
{
    app()->call([new EditCutWithAiJob($edit->id, [['start' => 0.0, 'end' => 1.5, 'speaker' => 1]]), 'handle']);
}

it('starts face tracking in the cuts style and claims once on a double click', function (): void {
    Bus::fake([StartFaceTrackingJob::class]);
    $cut = aiEditCut($this->fixture);

    Livewire::actingAs($cut->video->user)
        ->test(Show::class, ['uuid' => $cut->video->uuid])
        ->call('editWithAi', $cut->id, '  o loiro é o Castanhari ')
        ->call('editWithAi', $cut->id, '');

    $edit = VideoCutEdit::query()->where('video_cut_id', $cut->id)->sole();

    expect($edit->ai_status)->toBe(TranscriptionStatusEnum::Processing)
        ->and($edit->tracking_status)->toBe(TranscriptionStatusEnum::Processing)
        ->and($edit->ai_request)->toBe('o loiro é o Castanhari');

    Bus::assertDispatchedTimes(StartFaceTrackingJob::class, 1);
    Bus::assertDispatched(StartFaceTrackingJob::class, fn (StartFaceTrackingJob $job): bool => $job->editId === $edit->id && $job->style === 'cuts');
});

it('saves the chosen caption preset and refuses one outside the list', function (): void {
    Bus::fake([StartFaceTrackingJob::class]);
    $cut = aiEditCut($this->fixture);
    $component = Livewire::actingAs($cut->video->user)->test(Show::class, ['uuid' => $cut->video->uuid]);

    $component->call('editWithAi', $cut->id, '', 'amarelo');

    expect(VideoCutEdit::query()->where('video_cut_id', $cut->id)->exists())->toBeFalse();

    $component->call('editWithAi', $cut->id, '', 'branco_limpo');

    expect(VideoCutEdit::query()->where('video_cut_id', $cut->id)->sole()->caption_preset)->toBe('branco_limpo');
    Bus::assertDispatchedTimes(StartFaceTrackingJob::class, 1);
});

it('refuses a cut whose clip transcription is not ready', function (): void {
    Bus::fake([StartFaceTrackingJob::class]);
    $cut = aiEditCut($this->fixture, ['transcription_status' => TranscriptionStatusEnum::Processing]);

    Livewire::actingAs($cut->video->user)
        ->test(Show::class, ['uuid' => $cut->video->uuid])
        ->call('editWithAi', $cut->id, '');

    expect(VideoCutEdit::query()->where('video_cut_id', $cut->id)->exists())->toBeFalse();
    Bus::assertNotDispatched(StartFaceTrackingJob::class);
});

it('hands the tracking result to the AI edit only when the AI asked for it', function (): void {
    Bus::fake([EditCutWithAiJob::class]);
    $cut = aiEditCut($this->fixture);
    $aiEdit = aiEditFor($cut, ['tracking_status' => TranscriptionStatusEnum::Processing]);
    $manualEdit = aiEditFor($cut, ['tracking_status' => TranscriptionStatusEnum::Processing, 'ai_status' => null]);
    $payload = fn (VideoCutEdit $edit): array => [
        'uuid' => $edit->uuid,
        'status' => 'done',
        'keyframes' => [['t' => 0.0, 'mode' => 'vertical', 'regions' => [['x' => 0.1, 'y' => 0.2, 'w' => 0.2, 'h' => 0.6]]]],
        'speakers' => [['start' => 0.0, 'end' => 1.5, 'speaker' => 1]],
        'source' => ['width' => 1920, 'height' => 1080, 'duration' => 96.0],
    ];

    $this->postJson('/api/webhook/face-tracking', $payload($aiEdit), ['X-Observability-Token' => 'test-token'])->assertOk();
    $this->postJson('/api/webhook/face-tracking', $payload($manualEdit), ['X-Observability-Token' => 'test-token'])->assertOk();

    Bus::assertDispatchedTimes(EditCutWithAiJob::class, 1);
    Bus::assertDispatched(EditCutWithAiJob::class, fn (EditCutWithAiJob $job): bool => $job->editId === $aiEdit->id
        && $job->speakers === [['start' => 0.0, 'end' => 1.5, 'speaker' => 1]]);
});

it('fails the AI edit visibly when the tracking fails', function (): void {
    Bus::fake([EditCutWithAiJob::class]);
    $edit = aiEditFor(aiEditCut($this->fixture), ['tracking_status' => TranscriptionStatusEnum::Processing]);

    $this->postJson('/api/webhook/face-tracking', ['uuid' => $edit->uuid, 'status' => 'failed', 'error' => 'sem rosto'], ['X-Observability-Token' => 'test-token'])
        ->assertOk();

    expect($edit->fresh()?->ai_status)->toBe(TranscriptionStatusEnum::Failed)
        ->and($edit->fresh()?->ai_error)->toBe('Face tracking falhou: sem rosto');
    Bus::assertNotDispatched(EditCutWithAiJob::class);
});

it('writes the validated spec and renders straight away', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    Process::fake(['*' => claudeEditResult($this->fixture['spec'])]);
    $edit = aiEditFor(aiEditCut($this->fixture));

    runEditCutWithAi($edit);

    $fresh = $edit->fresh();

    expect($fresh?->ai_status)->toBe(TranscriptionStatusEnum::Ready)
        ->and($fresh?->render_status)->toBe(VideoCutStatusEnum::Generating)
        ->and($fresh?->spec['title'])->toBe('CASTANHARI beijou o SOVACO de língua 😂😂😂 @programapanico')
        ->and($fresh?->spec['cuts'])->toBe([[0.0, 0.73], [95.05, 96.0]]);

    Bus::assertDispatched(StartVideoCutEditRenderJob::class, fn (StartVideoCutEditRenderJob $job): bool => $job->editId === $edit->id);
    Process::assertRanTimes(fn (PendingProcess $process): bool => str_contains((string) $process->input, 'Pedido do dono: o loiro é o Castanhari')
        && str_contains((string) $process->input, 'Locutores: [0.0-1.5] 1')
        && str_contains((string) $process->input, '0|0.26|0.26|não,')
        && in_array('--json-schema', (array) $process->command, true), 1);
});

it('renders with the chosen caption preset and shows on the card what the AI could not do', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    Process::fake(['*' => claudeEditResult([...$this->fixture['spec'], 'ignored_request' => 'legenda amarela: não existe esse preset'])]);
    $cut = aiEditCut($this->fixture);
    $edit = aiEditFor($cut, ['caption_preset' => 'branco_limpo']);

    runEditCutWithAi($edit);

    expect($edit->fresh()?->spec['caption_preset'])->toBe('branco_limpo');

    Livewire::actingAs($cut->video->user)
        ->test(Show::class, ['uuid' => $cut->video->uuid])
        ->assertSee('A IA não conseguiu: legenda amarela: não existe esse preset');
});

it('fails without rendering when the AI rejects the cut', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    Process::fake(['*' => claudeEditResult(['verdict' => 'reject', 'reason' => 'conversa séria', 'cuts' => [], 'captions' => [], 'notes' => [], 'punches' => [], 'title' => '', 'hashtags' => []])]);
    $edit = aiEditFor(aiEditCut($this->fixture));

    runEditCutWithAi($edit);

    expect($edit->fresh()?->ai_status)->toBe(TranscriptionStatusEnum::Failed)
        ->and($edit->fresh()?->ai_error)->toStartWith('IA recusou o corte: conversa séria')
        ->and($edit->fresh()?->spec)->toBeNull();
    Process::assertRanTimes(fn (): bool => true, 1);
    Bus::assertNotDispatched(StartVideoCutEditRenderJob::class);
});

it('retries once with the soft errors and renders the fixed spec', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    Process::fake(['*' => Process::sequence()
        ->push(claudeEditResult([...$this->fixture['spec'], 'title' => '']))
        ->push(claudeEditResult($this->fixture['spec']))]);
    $edit = aiEditFor(aiEditCut($this->fixture));

    runEditCutWithAi($edit);

    expect($edit->fresh()?->ai_status)->toBe(TranscriptionStatusEnum::Ready);
    Process::assertRanTimes(fn (): bool => true, 2);
    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->input, 'Sua resposta anterior:')
        && str_contains((string) $process->input, '- título vazio'));
    Bus::assertDispatched(StartVideoCutEditRenderJob::class);
});

it('fails with the error list when the retry still breaks the rules', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    Process::fake(['*' => claudeEditResult([...$this->fixture['spec'], 'title' => ''])]);
    $edit = aiEditFor(aiEditCut($this->fixture));

    runEditCutWithAi($edit);

    expect($edit->fresh()?->ai_status)->toBe(TranscriptionStatusEnum::Failed)
        ->and($edit->fresh()?->ai_error)->toBe('título vazio');
    Process::assertRanTimes(fn (): bool => true, 2);
    Bus::assertNotDispatched(StartVideoCutEditRenderJob::class);
});

it('shows the claude error on the edit when the call fails', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    Process::fake(['*' => claudeEditResult([], isError: true)]);
    $edit = aiEditFor(aiEditCut($this->fixture));

    expect(fn () => runEditCutWithAi($edit))->toThrow(ClaudeException::class);

    new EditCutWithAiJob($edit->id)->failed(ClaudeException::usageLimit());

    expect($edit->fresh()?->ai_status)->toBe(TranscriptionStatusEnum::Failed)
        ->and($edit->fresh()?->ai_error)->toContain('limite de uso');
    Bus::assertNotDispatched(StartVideoCutEditRenderJob::class);
});

it('keeps the editor from rendering or re-tracking while the AI is editing', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class, StartFaceTrackingJob::class]);
    $cut = aiEditCut($this->fixture);
    $edit = aiEditFor($cut);

    Livewire::actingAs($cut->video->user)
        ->test(VideoEditor::class, ['uuid' => $cut->uuid])
        ->set('editId', $edit->id)
        ->call('generateRender')
        ->assertReturned(null)
        ->call('generateTracking')
        ->assertReturned(null);

    expect($edit->fresh()?->render_status)->toBeNull()
        ->and($edit->fresh()?->tracking_status)->toBe(TranscriptionStatusEnum::Ready);
    Bus::assertNotDispatched(StartVideoCutEditRenderJob::class);
    Bus::assertNotDispatched(StartFaceTrackingJob::class);
});

it('does not start a second render when a manual one began during the claude call', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    Process::fake(['*' => claudeEditResult($this->fixture['spec'])]);
    $edit = aiEditFor(aiEditCut($this->fixture), ['render_status' => VideoCutStatusEnum::Generating]);

    runEditCutWithAi($edit);

    expect($edit->fresh()?->ai_status)->toBe(TranscriptionStatusEnum::Failed)
        ->and($edit->fresh()?->ai_error)->toStartWith('Um render manual começou')
        ->and($edit->fresh()?->spec)->toBeNull();
    Bus::assertNotDispatched(StartVideoCutEditRenderJob::class);
});

it('offers the approved stickers to claude and renders without the ones that break the rules', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    $sticker = StockAsset::query()->create(['id' => 'f3b1c2d4-0000-7000-8000-000000000001', 'kind' => 'meme_sticker', 'tags' => ['susto'], 'license' => 'own_risk', 'source' => 'manual', 'storage_key' => 'assets/meme_sticker/1.png', 'status' => 'approved']);
    $word = $this->fixture['spec']['captions'][1]['w'][0];
    Process::fake(['*' => claudeEditResult([...$this->fixture['spec'], 'memes' => [
        ['asset_id' => $sticker->id, 'w' => $word],
        ['asset_id' => $sticker->id, 'w' => $this->fixture['spec']['captions'][20]['w'][0]],
    ]])]);
    $edit = aiEditFor(aiEditCut($this->fixture));

    runEditCutWithAi($edit);

    $command = [];
    Process::assertRan(function (PendingProcess $process) use (&$command): bool {
        $command = (array) $process->command;

        return true;
    });
    $schema = json_decode($command[array_search('--json-schema', $command, true) + 1], true, 512, JSON_THROW_ON_ERROR);

    expect($edit->fresh()?->ai_status)->toBe(TranscriptionStatusEnum::Ready)
        ->and($edit->fresh()?->spec['memes'])->toHaveCount(1)
        ->and($edit->fresh()?->spec['memes'][0]['key'])->toBe('assets/meme_sticker/1.png')
        ->and($schema['properties']['memes']['items']['properties']['asset_id']['enum'])->toBe([$sticker->id])
        ->and($schema['properties'])->not->toHaveKey('sfx')
        ->and($command[array_search('--system-prompt', $command, true) + 1])->toContain('#### `memes`', $sticker->id);
    Process::assertRanTimes(fn (): bool => true, 1);
    Bus::assertDispatched(StartVideoCutEditRenderJob::class);
});

it('renders the wikipedia images it resolves with their credit and drops the ones it cannot', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    Http::fake([
        'upload.wikimedia.org/*' => Http::response((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAYAAAC56t6BAAAAEklEQVR4nGP4z8Dwn4GBgQEAFAUCAU2a6TQAAAAASUVORK5CYII=', true), 200, ['Content-Type' => 'image/png']),
        '*.wikipedia.org/*' => fn (Request $request) => Http::response(['query' => ['pages' => [match (true) {
            str_contains($request->url(), 'Banana') => ['title' => 'Banana', 'pageprops' => ['disambiguation' => '']],
            str_contains($request->url(), 'imageinfo') => ['imageinfo' => [[
                'thumburl' => 'https://upload.wikimedia.org/thumb/1000px-Sovaco.png',
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Sovaco.png',
                'extmetadata' => ['License' => ['value' => 'cc-by-4.0'], 'Artist' => ['value' => 'Fulano']],
            ]]],
            default => ['title' => 'Axila', 'pageimage' => 'Sovaco.png'],
        }]]]),
    ]);
    Process::fake(['*' => claudeEditResult([...$this->fixture['spec'], 'images' => [
        ['w' => $this->fixture['spec']['captions'][1]['w'][0], 'wikipedia_title' => 'Axila', 'lang' => 'pt', 'size' => 'card'],
        ['w' => $this->fixture['spec']['captions'][20]['w'][0], 'wikipedia_title' => 'Banana', 'lang' => 'pt', 'size' => 'small'],
    ]])]);
    $edit = aiEditFor(aiEditCut($this->fixture));

    runEditCutWithAi($edit);

    $images = $edit->fresh()?->spec['images'] ?? [];

    expect($edit->fresh()?->ai_status)->toBe(TranscriptionStatusEnum::Ready)
        ->and($images)->toHaveCount(1)
        ->and($images[0]['size'])->toBe('card')
        ->and($images[0]['key'])->toStartWith('assets/image/')
        ->and($images[0]['credit'])->toBe('Imagem: Fulano, CC BY https://commons.wikimedia.org/wiki/File:Sovaco.png');
    Storage::disk('s3')->assertExists($images[0]['key']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'titles=Banana'));
    Bus::assertDispatched(StartVideoCutEditRenderJob::class);
});

it('renders without the image when storing it in the stock fails', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class]);
    Storage::disk('s3')->put('assets/image', 'um arquivo no lugar da pasta: o upload falha');
    Http::fake([
        'upload.wikimedia.org/*' => Http::response('png', 200, ['Content-Type' => 'image/png']),
        '*.wikipedia.org/*' => fn (Request $request) => Http::response(['query' => ['pages' => [str_contains($request->url(), 'imageinfo')
            ? ['imageinfo' => [['thumburl' => 'https://upload.wikimedia.org/thumb/1000px-Sovaco.png', 'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Sovaco.png', 'extmetadata' => ['License' => ['value' => 'cc0']]]]]
            : ['title' => 'Axila', 'pageimage' => 'Sovaco.png']]]]),
    ]);
    Process::fake(['*' => claudeEditResult([...$this->fixture['spec'], 'images' => [
        ['w' => $this->fixture['spec']['captions'][1]['w'][0], 'wikipedia_title' => 'Axila', 'lang' => 'pt', 'size' => 'card'],
    ]])]);
    $edit = aiEditFor(aiEditCut($this->fixture));

    runEditCutWithAi($edit);

    expect($edit->fresh()?->ai_status)->toBe(TranscriptionStatusEnum::Ready)
        ->and($edit->fresh()?->spec['images'])->toBe([]);
    Bus::assertDispatched(StartVideoCutEditRenderJob::class);
});

it('redoes the short with the previous answer and the change and replaces it in the stock', function (): void {
    Bus::fake([StartVideoCutEditRenderJob::class, EditCutWithAiJob::class]);
    $sticker = StockAsset::query()->create(['id' => 'f3b1c2d4-0000-7000-8000-000000000001', 'kind' => 'meme_sticker', 'tags' => ['susto'], 'license' => 'own_risk', 'source' => 'manual', 'storage_key' => 'assets/meme_sticker/1.png', 'status' => 'approved']);
    $first = [...$this->fixture['spec'], 'memes' => [['asset_id' => $sticker->id, 'w' => $this->fixture['spec']['captions'][1]['w'][0]]]];
    Process::fake(['*' => Process::sequence()
        ->push(claudeEditResult($first))
        ->push(claudeEditResult([...$first, 'title' => 'título refeito']))]);
    $cut = aiEditCut($this->fixture);
    $edit = aiEditFor($cut);
    $webhook = fn () => $this->postJson('/api/webhook/video-cut-edit', ['edit_uuid' => $edit->uuid, 'status' => 'done'], ['X-Observability-Token' => 'test-token'])->assertOk();

    runEditCutWithAi($edit);
    $webhook();

    $short = YoutubeShort::query()->sole();
    $short->update(['title' => 'título do dono', 'ready_at' => now()->subDay()]);

    $readyAt = $short->fresh()?->ready_at?->toDateTimeString();
    $preset = $edit->fresh()?->spec['caption_preset'];

    Livewire::actingAs($cut->video->user)
        ->test(Videos::class)
        ->call('redo', $short->id, '  mais memes ')
        ->call('redo', $short->id, 'de novo')
        ->assertSee('Refazendo…');

    Bus::assertDispatchedTimes(EditCutWithAiJob::class, 1);
    Bus::assertDispatched(EditCutWithAiJob::class, fn (EditCutWithAiJob $job): bool => $job->editId === $edit->id && $job->change === 'mais memes' && $job->speakers === []);

    app()->call([new EditCutWithAiJob($edit->id, change: 'mais memes'), 'handle']);
    $webhook();

    Process::assertRanTimes(fn (): bool => true, 2);
    Process::assertRan(function (PendingProcess $process) use ($sticker): bool {
        $command = (array) $process->command;

        return str_contains((string) $process->input, "Edição anterior:\n{\"verdict\":\"edit\"")
            && str_contains((string) $process->input, 'Mudança pedida: mais memes')
            && str_contains((string) $command[array_search('--json-schema', $command, true) + 1], $sticker->id);
    });

    expect($edit->fresh()?->spec['title'])->toBe('título refeito')
        ->and($edit->fresh()?->spec['ai_output']['title'] ?? null)->toBe('título refeito')
        ->and($edit->fresh()?->spec['caption_preset'])->toBe($preset)
        ->and(YoutubeShort::query()->sole()->id)->toBe($short->id)
        ->and($short->fresh()?->title)->toBe('título do dono')
        ->and($short->fresh()?->ready_at?->toDateTimeString())->toBe($readyAt);
});

it('refuses an empty change or a short without the stored AI answer and hides the old error while redoing', function (): void {
    Bus::fake([EditCutWithAiJob::class]);
    $cut = aiEditCut($this->fixture);
    $old = YoutubeShort::factory()->create();
    $redoable = YoutubeShort::factory()->create();
    aiEditFor($cut, ['ai_status' => TranscriptionStatusEnum::Ready, 'spec' => ['caption_preset' => 'verde'], 'youtube_short_id' => $old->id]);
    aiEditFor($cut, ['ai_status' => TranscriptionStatusEnum::Ready, 'spec' => ['caption_preset' => 'verde', 'ai_output' => ['title' => 't']], 'youtube_short_id' => $redoable->id, 'render_status' => VideoCutStatusEnum::Failed, 'render_error' => 'render antigo falhou']);

    Livewire::actingAs($cut->video->user)
        ->test(Videos::class)
        ->assertViewHas('downloaded', function (array $cards) use ($old, $redoable): bool {
            $canRedo = array_column($cards, 'canRedo', 'id');

            return $canRedo[$old->id] === false && $canRedo[$redoable->id] === true;
        })
        ->assertSee('render antigo falhou')
        ->call('redo', $old->id, 'mais memes')
        ->call('redo', $redoable->id, '   ')
        ->call('redo', $redoable->id, 'mais memes')
        ->assertSee('Refazendo…')
        ->assertDontSee('render antigo falhou');

    Bus::assertDispatchedTimes(EditCutWithAiJob::class, 1);
});
