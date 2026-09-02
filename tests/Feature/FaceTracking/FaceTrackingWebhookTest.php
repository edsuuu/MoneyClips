<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoCutEdit;
use Illuminate\Support\Facades\Storage;

function makeTrackingEdit(?string $trackingStatus = TranscriptionStatusEnum::Processing->value): VideoCutEdit
{
    $video = Video::factory()->ready()->create(['user_id' => User::factory()->create()->id]);

    $cut = $video->cuts()->create([
        'start_seconds' => 5,
        'end_seconds' => 65,
        'status' => VideoCutStatusEnum::Ready,
    ]);

    return VideoCutEdit::factory()->create([
        'video_cut_id' => $cut->id,
        'tracking_status' => $trackingStatus,
    ]);
}

function trackingPayload(string $uuid, array $overrides = []): array
{
    return array_merge([
        'uuid' => $uuid,
        'status' => 'done',
        'keyframes' => [
            ['t' => 0.0, 'mode' => 'vertical', 'regions' => [['x' => 0.1, 'y' => 0.0, 'w' => 0.3164, 'h' => 1.0]]],
            ['t' => 2.0, 'mode' => 'vertical', 'regions' => [['x' => 0.5, 'y' => 0.0, 'w' => 0.3164, 'h' => 1.0]]],
        ],
        'speakers' => [
            ['start' => 0.0, 'end' => 1.5, 'speaker' => 1],
            ['start' => 1.5, 'end' => 4.0, 'speaker' => 2],
        ],
        'source' => ['width' => 1920, 'height' => 1080, 'duration' => 60.0],
    ], $overrides);
}

beforeEach(function (): void {
    config(['services.observability.token' => 'test-token']);
    $this->headers = ['X-Observability-Token' => 'test-token'];
    Storage::fake('s3');
});

it('refuses a tracking webhook without the shared token', function (): void {
    $edit = makeTrackingEdit();

    $this->postJson('/api/webhook/face-tracking', trackingPayload($edit->uuid))
        ->assertUnauthorized();

    expect($edit->fresh()?->tracking_status)->toBe(TranscriptionStatusEnum::Processing);
});

it('answers 404 for an unknown edit', function (): void {
    $this->postJson('/api/webhook/face-tracking', trackingPayload('nao-existe'), $this->headers)
        ->assertNotFound();
});

it('writes the keyframes onto the edit the operator will open', function (): void {
    $edit = makeTrackingEdit();

    $this->postJson('/api/webhook/face-tracking', trackingPayload($edit->uuid), $this->headers)
        ->assertOk();

    $fresh = $edit->fresh();

    expect($fresh?->tracking_status)->toBe(TranscriptionStatusEnum::Ready)
        ->and($fresh?->keyframes)->toHaveCount(2)
        ->and((float) $fresh?->keyframes[0]['t'])->toBe(0.0)
        ->and($fresh?->keyframes[1]['regions'][0]['x'])->toBe(0.5)
        ->and($fresh?->mode)->toBe('vertical');
});

it('seeds one caption color per detected speaker without touching the other settings', function (): void {
    $edit = makeTrackingEdit();
    $edit->update(['settings' => ['version' => 1, 'background' => '#101010', 'captionColor' => '#abcdef']]);

    $this->postJson('/api/webhook/face-tracking', trackingPayload($edit->uuid), $this->headers)
        ->assertOk();

    $settings = $edit->fresh()?->settings;

    expect($settings['speakerColors'])->toHaveCount(2)
        ->and($settings['speakerColors'][1])->toStartWith('#')
        ->and($settings['speakerColors'][1])->not->toBe($settings['speakerColors'][2])
        ->and($settings['background'])->toBe('#101010')
        ->and($settings['captionColor'])->toBe('#abcdef');
});

it('keeps a colour the operator already chose for a speaker', function (): void {
    $edit = makeTrackingEdit();
    $edit->update(['settings' => ['version' => 1, 'background' => '#000000', 'speakerColors' => [1 => '#123456']]]);

    $this->postJson('/api/webhook/face-tracking', trackingPayload($edit->uuid), $this->headers)
        ->assertOk();

    expect($edit->fresh()?->settings['speakerColors'][1])->toBe('#123456');
});

it('stores the speaker timeline next to the transcript', function (): void {
    $edit = makeTrackingEdit();

    $this->postJson('/api/webhook/face-tracking', trackingPayload($edit->uuid), $this->headers)
        ->assertOk();

    $path = $edit->videoCut?->speakersPath();

    Storage::disk('s3')->assertExists($path);

    $stored = json_decode((string) Storage::disk('s3')->get($path), true, 512, JSON_THROW_ON_ERROR);

    expect($stored['speakers'])->toHaveCount(2)
        ->and($stored['speakers'][1]['speaker'])->toBe(2);
});

it('ignores a redelivered webhook instead of overwriting manual edits', function (): void {
    $edit = makeTrackingEdit();

    $this->postJson('/api/webhook/face-tracking', trackingPayload($edit->uuid), $this->headers)->assertOk();

    $edit->fresh()->update([
        'keyframes' => [['t' => 9.0, 'mode' => 'vertical', 'regions' => [['x' => 0.9, 'y' => 0.0, 'w' => 0.1, 'h' => 1.0]]]],
    ]);

    $this->postJson('/api/webhook/face-tracking', trackingPayload($edit->uuid), $this->headers)->assertOk();

    expect($edit->fresh()?->keyframes)->toHaveCount(1)
        ->and((float) $edit->fresh()?->keyframes[0]['t'])->toBe(9.0);
});

it('rejects a region outside the normalised range', function (): void {
    $edit = makeTrackingEdit();

    $this->postJson('/api/webhook/face-tracking', trackingPayload($edit->uuid, [
        'keyframes' => [
            ['t' => 0.0, 'mode' => 'vertical', 'regions' => [['x' => 1.4, 'y' => 0.0, 'w' => 0.3, 'h' => 1.0]]],
        ],
    ]), $this->headers)->assertStatus(422);

    expect($edit->fresh()?->tracking_status)->toBe(TranscriptionStatusEnum::Processing)
        ->and($edit->fresh()?->keyframes)->toHaveCount(1)
        ->and($edit->fresh()?->keyframes[0]['x'] ?? null)->toBeNull();
});

it('records the error when the service reports a failure', function (): void {
    $edit = makeTrackingEdit();

    $this->postJson('/api/webhook/face-tracking', [
        'uuid' => $edit->uuid,
        'status' => 'failed',
        'error' => 'nenhum rosto detectado',
    ], $this->headers)->assertOk();

    expect($edit->fresh()?->tracking_status)->toBe(TranscriptionStatusEnum::Failed)
        ->and($edit->fresh()?->tracking_error)->toBe('nenhum rosto detectado');
});
