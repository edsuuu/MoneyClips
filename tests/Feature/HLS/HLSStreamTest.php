<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('s3');
    config(['services.hls.delivery' => 'stream']);

    $this->user = User::factory()->create();
    $this->video = Video::factory()->ready()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

it('keeps the stream behind authentication', function (): void {
    auth()->logout();

    $this->get(sprintf('/hls/%s/master.m3u8', $this->video->uuid))->assertRedirect();
    $this->get(sprintf('/hls/%s/720p/seg_00000.m4s', $this->video->uuid))->assertRedirect();
});

it('serves the master playlist to the owner', function (): void {
    Storage::disk('s3')->put($this->video->masterPlaylistPath(), '#EXTM3U');

    $this->get(sprintf('/hls/%s/master.m3u8', $this->video->uuid))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
});

it('marks segments immutable and playlists uncacheable', function (): void {
    Storage::disk('s3')->put($this->video->hlsPrefix().'/720p/seg_00000.m4s', 'bytes');
    Storage::disk('s3')->put($this->video->hlsPrefix().'/720p/index.m3u8', '#EXTM3U');

    $segment = $this->get(sprintf('/hls/%s/720p/seg_00000.m4s', $this->video->uuid))->assertOk();
    $playlist = $this->get(sprintf('/hls/%s/720p/index.m3u8', $this->video->uuid))->assertOk();

    expect($segment->headers->get('Cache-Control'))->toContain('immutable')
        ->and($playlist->headers->get('Cache-Control'))->toContain('no-cache');
});

it('serves the init segment that ffmpeg names per rendition', function (): void {
    Storage::disk('s3')->put($this->video->hlsPrefix().'/720p/init_1.mp4', 'bytes');

    $this->get(sprintf('/hls/%s/720p/init_1.mp4', $this->video->uuid))
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');
});

it('refuses anything outside the allowlist', function (string $path): void {
    $this->get(sprintf('/hls/%s/%s', $this->video->uuid, $path))->assertNotFound();
})->with([
    '../../.env',
    '720p/../../../etc/passwd',
    '720p/evil.sh',
    'evil/../../x',
    '720p/seg_00000.m4s.bak',
]);

it('does not stream a video that is not ready yet', function (): void {
    $packaging = Video::factory()->packaging()->create(['user_id' => $this->user->id]);
    Storage::disk('s3')->put($packaging->masterPlaylistPath(), '#EXTM3U');

    $this->get(sprintf('/hls/%s/master.m3u8', $packaging->uuid))->assertNotFound();
});
