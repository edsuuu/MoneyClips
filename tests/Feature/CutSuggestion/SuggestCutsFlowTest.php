<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatusEnum;
use App\Exceptions\ClaudeException;
use App\Jobs\SuggestCutsJob;
use App\Livewire\Uploads\Show;
use App\Models\Video;
use App\Services\CutSuggestion\CutSuggestionData;
use App\Services\CutSuggestion\CutSuggestionInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function searchingVideo(): Video
{
    Storage::fake('s3');

    $video = Video::factory()->ready()->create([
        'duration_seconds' => 2000,
        'transcription_status' => TranscriptionStatusEnum::Ready,
        'cut_suggestion_status' => TranscriptionStatusEnum::Processing,
    ]);
    Storage::disk('s3')->put($video->transcriptPath(), (string) json_encode(['segments' => []]));

    return $video;
}

it('dispatches a single search on a double click', function (): void {
    Bus::fake();
    Storage::fake('s3');
    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Ready]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('suggestAiCuts')
        ->call('suggestAiCuts');

    Bus::assertDispatchedTimes(SuggestCutsJob::class, 1);
    expect($video->fresh()?->cut_suggestion_status)->toBe(TranscriptionStatusEnum::Processing);
});

it('shows the search failure on the page', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create([
        'transcription_status' => TranscriptionStatusEnum::Ready,
        'cut_suggestion_status' => TranscriptionStatusEnum::Failed,
        'cut_suggestion_error' => 'O Claude falhou (exit 1): limite atingido',
    ]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->assertSee('O Claude falhou (exit 1): limite atingido');
});

it('skips suggestions that overlap an existing cut and marks the search ready', function (): void {
    $video = searchingVideo();
    $video->cuts()->create(['start_seconds' => 100, 'end_seconds' => 180]);

    $this->mock(CutSuggestionInterface::class)->shouldReceive('suggest')->andReturn([
        new CutSuggestionData(150.0, 250.0, 9, 'sobrepõe o manual'),
        new CutSuggestionData(500.0, 600.4, 7, 'novo'),
    ]);

    dispatch_sync(new SuggestCutsJob($video->id, ''));

    $created = $video->cuts()->where('is_ai_generated', true)->sole();

    expect($video->cuts()->count())->toBe(2)
        ->and($created->start_seconds)->toBe(500.0)
        ->and($created->end_seconds)->toBe(600.4)
        ->and($video->fresh()?->cut_suggestion_status)->toBe(TranscriptionStatusEnum::Ready);
});

it('extends a border cut mid-word to the word edge, keeps the laughter and stores the metadata', function (): void {
    $video = searchingVideo();
    Storage::disk('s3')->put($video->transcriptPath(), (string) json_encode(['segments' => [
        ['start' => 100.0, 'end' => 180.0, 'words' => [
            ['word' => 'oi', 'start' => 99.6, 'end' => 100.9],
            ['word' => 'tchau', 'start' => 179.1, 'end' => 179.77],
        ]],
        ['start' => 180.0, 'end' => 190.0, 'words' => [['word' => 'depois', 'start' => 181.0, 'end' => 181.5]]],
    ]]));

    $this->mock(CutSuggestionInterface::class)->shouldReceive('suggest')->andReturn([
        new CutSuggestionData(100.0, 180.0, 8, 'setup -> punchline', 'PAUL tirando JACQUIN', ['#humor', '#podcast']),
    ]);

    dispatch_sync(new SuggestCutsJob($video->id, ''));

    $cut = $video->cuts()->sole();

    expect($cut->start_seconds)->toBe(99.6)
        ->and($cut->end_seconds)->toBe(180.0)
        ->and($cut->score)->toBe(8)
        ->and($cut->reason)->toBe('setup -> punchline')
        ->and($cut->title)->toBe('PAUL tirando JACQUIN')
        ->and($cut->hashtags)->toBe(['#humor', '#podcast']);
});

it('persists two suggestions that only touch each other', function (): void {
    $video = searchingVideo();

    $this->mock(CutSuggestionInterface::class)->shouldReceive('suggest')->andReturn([
        new CutSuggestionData(395.5, 509.7, 8, 'antes'),
        new CutSuggestionData(509.7, 613.4, 6, 'suco'),
    ]);

    dispatch_sync(new SuggestCutsJob($video->id, ''));

    expect($video->cuts()->orderBy('start_seconds')->get(['start_seconds', 'end_seconds'])->toArray())->toBe([
        ['start_seconds' => 395.5, 'end_seconds' => 509.7],
        ['start_seconds' => 509.7, 'end_seconds' => 613.4],
    ]);
});

it('shows title, reason and score on the cut card', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create(['duration_seconds' => 2000]);
    $video->cuts()->create(['start_seconds' => 10.5, 'end_seconds' => 90.2, 'title' => 'PAUL tirando JACQUIN', 'reason' => 'setup -> risada', 'score' => 9]);
    $video->cuts()->create(['start_seconds' => 200, 'end_seconds' => 260]);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->assertSee('PAUL tirando JACQUIN')
        ->assertSee('setup -> risada')
        ->assertSee('Nota 9/10')
        ->assertSee('Corte 2')
        ->assertDontSee('Corte 1');
});

it('tells the owner when no new moment was found', function (): void {
    $video = searchingVideo();
    $video->cuts()->create(['start_seconds' => 100, 'end_seconds' => 180]);

    $this->mock(CutSuggestionInterface::class)->shouldReceive('suggest')->andReturn([
        new CutSuggestionData(150.0, 250.0, 9, 'sobrepõe o manual'),
    ]);

    dispatch_sync(new SuggestCutsJob($video->id, ''));

    expect($video->fresh()?->cut_suggestion_status)->toBe(TranscriptionStatusEnum::Failed)
        ->and($video->fresh()?->cut_suggestion_error)->toContain('Nenhum momento novo');
});

it('records the reason when the provider fails', function (): void {
    $video = searchingVideo();

    $this->mock(CutSuggestionInterface::class)->shouldReceive('suggest')->andThrow(ClaudeException::failed(1, 'limite atingido'));

    expect(fn () => dispatch_sync(new SuggestCutsJob($video->id, '')))->toThrow(ClaudeException::class);

    expect($video->fresh()?->cut_suggestion_status)->toBe(TranscriptionStatusEnum::Failed)
        ->and($video->fresh()?->cut_suggestion_error)->toBe('O Claude falhou (exit 1): limite atingido');
});
