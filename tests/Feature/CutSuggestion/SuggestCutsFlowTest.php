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
        ->and($created->start_seconds)->toBe(500)
        ->and($created->end_seconds)->toBe(601)
        ->and($video->fresh()?->cut_suggestion_status)->toBe(TranscriptionStatusEnum::Ready);
});

it('persists two suggestions that only touch each other despite the integer rounding', function (): void {
    $video = searchingVideo();

    $this->mock(CutSuggestionInterface::class)->shouldReceive('suggest')->andReturn([
        new CutSuggestionData(395.5, 509.7, 8, 'antes'),
        new CutSuggestionData(509.7, 613.4, 6, 'suco'),
    ]);

    dispatch_sync(new SuggestCutsJob($video->id, ''));

    expect($video->cuts()->orderBy('start_seconds')->get(['start_seconds', 'end_seconds'])->toArray())->toBe([
        ['start_seconds' => 395, 'end_seconds' => 510],
        ['start_seconds' => 509, 'end_seconds' => 614],
    ]);
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
