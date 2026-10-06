<?php

declare(strict_types=1);

use App\Models\VideoCutEdit;
use App\Services\Video\FaceTrackingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.face_tracking.base_url', 'http://media.test');
    config()->set('services.face_tracking.max_keyframes', 40);
    Http::fake(['media.test/face-tracking' => Http::response(['job_id' => 'job-1', 'status' => 'queued'], 202)]);

    $this->clip = (string) tempnam(sys_get_temp_dir(), 'face-tracking-clip-');
    file_put_contents($this->clip, 'fake-clip');
});

afterEach(function (): void {
    @unlink($this->clip);
});

it('asks the media for the smooth tracking by default', function (): void {
    $edit = VideoCutEdit::factory()->make();

    $jobId = resolve(FaceTrackingService::class)->createTracking($this->clip, $edit);

    expect($jobId)->toBe('job-1');
    Http::assertSent(fn (Request $request): bool => $request->hasFile('uuid', $edit->uuid)
        && $request->hasFile('style', 'smooth')
        && $request->hasFile('max_keyframes', '40')
        && $request->hasFile('video'));
});

it('forwards the cuts style with room for two keyframes per hard cut', function (): void {
    resolve(FaceTrackingService::class)->createTracking($this->clip, VideoCutEdit::factory()->make(), 'cuts');

    Http::assertSent(fn (Request $request): bool => $request->hasFile('style', 'cuts')
        && $request->hasFile('max_keyframes', '120'));
});
