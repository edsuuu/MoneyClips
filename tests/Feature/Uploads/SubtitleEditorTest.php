<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatusEnum;
use App\Livewire\Uploads\Show;
use App\Models\File;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function seedTranscript(Video $video): void
{
    $video->files()->create(['type' => File::TRANSCRIPT, 'path' => $video->transcriptPath(), 'mime_type' => 'application/json']);
    Storage::disk('s3')->put($video->transcriptPath(), (string) json_encode([
        'language' => 'pt',
        'segments' => [
            ['start' => 0.0, 'end' => 1.0, 'text' => 'um', 'words' => [['word' => 'um', 'start' => 0.0, 'end' => 1.0]]],
            ['start' => 1.0, 'end' => 2.0, 'text' => 'dois', 'words' => [['word' => 'dois', 'start' => 1.0, 'end' => 2.0]]],
            ['start' => 2.0, 'end' => 3.0, 'text' => 'tres', 'words' => []],
        ],
    ]));
}

it('saves an edited segment text and preserves timings/words', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Ready]);
    seedTranscript($video);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('saveTranscript', [['i' => 1, 'text' => 'DOIS corrigido']])
        ->assertReturned(true);

    $segments = json_decode((string) Storage::disk('s3')->get($video->transcriptPath()), true)['segments'];

    expect($segments[1]['text'])->toBe('DOIS corrigido')
        ->and($segments[0]['text'])->toBe('um')
        ->and($segments[2]['text'])->toBe('tres')
        ->and($segments[1]['start'])->toEqual(1.0)
        ->and($segments[1]['words'][0]['word'])->toBe('dois');
});

it('rejects empty text without writing', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Ready]);
    seedTranscript($video);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('saveTranscript', [['i' => 0, 'text' => '   ']])
        ->assertReturned(false);

    $segments = json_decode((string) Storage::disk('s3')->get($video->transcriptPath()), true)['segments'];
    expect($segments[0]['text'])->toBe('um');
});

it('rejects an out-of-range index', function (): void {
    Storage::fake('s3');
    $video = Video::factory()->ready()->create(['transcription_status' => TranscriptionStatusEnum::Ready]);
    seedTranscript($video);

    Livewire::actingAs($video->user)
        ->test(Show::class, ['uuid' => $video->uuid])
        ->call('saveTranscript', [['i' => 99, 'text' => 'x']])
        ->assertReturned(false);
});
