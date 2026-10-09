<?php

declare(strict_types=1);

use App\Enums\VideoCutStatusEnum;
use App\Models\Video;
use App\Models\VideoCutEdit;
use App\Models\YoutubeShort;

it('backfills user_id from the edited cut owner and leaves channel shorts without owner', function (): void {
    $video = Video::factory()->ready()->create();
    $cut = $video->cuts()->create(['start_seconds' => 1, 'end_seconds' => 10, 'status' => VideoCutStatusEnum::Ready]);
    $edited = YoutubeShort::factory()->create();
    VideoCutEdit::factory()->create(['video_cut_id' => $cut->id, 'youtube_short_id' => $edited->id]);
    $fromChannel = YoutubeShort::factory()->create();

    $migration = require database_path('migrations/2026_10_08_234729_add_user_id_to_youtube_shorts_table.php');
    $migration->backfillOwnerFromEditedCut();

    expect($edited->refresh()->user_id)->toBe($video->user_id)
        ->and($fromChannel->refresh()->user_id)->toBeNull();
});
