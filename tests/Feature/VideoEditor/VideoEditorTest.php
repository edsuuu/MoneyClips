<?php

declare(strict_types=1);

use App\Enums\VideoCutStatusEnum;
use App\Livewire\VideoEditor\Index;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function makeReadyCut(?User $owner = null): VideoCut
{
    $video = Video::factory()->ready()->create($owner instanceof User ? ['user_id' => $owner->id] : []);

    return $video->cuts()->create([
        'start_seconds' => 5,
        'end_seconds' => 20,
        'status' => VideoCutStatusEnum::Ready,
    ]);
}

/** @return array<string, mixed> Payload válido de modo split com 2 keyframes. */
function validSplitPayload(): array
{
    $regions = [
        ['x' => 0.1, 'y' => 0.0, 'w' => 0.4, 'h' => 0.5],
        ['x' => 0.5, 'y' => 0.2, 'w' => 0.4, 'h' => 0.5],
    ];

    return [
        'editId' => null,
        'mode' => 'split',
        'keyframes' => [
            ['t' => 0.0, 'mode' => 'split', 'regions' => $regions],
            ['t' => 12.5, 'mode' => 'split', 'regions' => $regions],
        ],
        'settings' => ['version' => 1, 'background' => '#101828'],
        'sourceMeta' => ['width' => 1920, 'height' => 1080, 'duration' => 60.0],
    ];
}

it('renders the screen for the owner and redirects guests', function (): void {
    $cut = makeReadyCut($this->user);

    $this->get('/editor-de-video/'.$cut->uuid)->assertOk()->assertSeeLivewire('video-editor.index');

    Auth::logout();
    $this->get('/editor-de-video/'.$cut->uuid)->assertRedirect();
});

it('rejects cuts the user cannot edit', function (): void {
    $foreign = makeReadyCut();
    $this->get('/editor-de-video/'.$foreign->uuid)->assertNotFound();

    $draft = makeReadyCut($this->user);
    $draft->update(['status' => VideoCutStatusEnum::Draft]);
    $this->get('/editor-de-video/'.$draft->uuid)->assertNotFound();

    $this->get('/editor-de-video/nao-existe')->assertNotFound();
});

it('exposes a default editor payload when the cut has no edit yet', function (): void {
    $cut = makeReadyCut($this->user);

    Livewire::test(Index::class, ['uuid' => $cut->uuid])
        ->assertSet('editId', null)
        ->assertViewHas('editorPayload', fn (array $payload): bool => $payload['editId'] === null
            && $payload['mode'] === 'vertical'
            && $payload['keyframes'] === []
            && $payload['settings'] === ['version' => 1, 'background' => '#000000', 'captions' => false, 'captionColor' => '#ffffff', 'captionCase' => 'sentence']
            && $payload['sourceMeta'] === null);
});

it('creates an edit on save and updates it in place on the next save', function (): void {
    $cut = makeReadyCut($this->user);

    $component = Livewire::test(Index::class, ['uuid' => $cut->uuid])
        ->call('saveEdit', validSplitPayload())
        ->assertDispatched('toast');

    $edit = VideoCutEdit::query()->sole();
    expect($edit->uuid)->not->toBe('')
        ->and($edit->video_cut_id)->toBe($cut->id)
        ->and($edit->youtube_short_id)->toBeNull()
        ->and($edit->mode)->toBe('split')
        ->and($edit->keyframes)->toHaveCount(2)
        ->and($edit->keyframes[0]['mode'])->toBe('split')
        ->and($edit->settings)->toBe(['version' => 1, 'background' => '#101828', 'captions' => false, 'captionColor' => '#ffffff', 'captionCase' => 'sentence']);

    $component->assertSet('editId', $edit->id);

    $payload = validSplitPayload();
    $payload['editId'] = $edit->id;
    $payload['settings']['background'] = '#FFFFFF';
    $component->call('saveEdit', $payload);

    expect(VideoCutEdit::query()->count())->toBe(1)
        ->and($edit->refresh()->settings)->toBe(['version' => 1, 'background' => '#ffffff', 'captions' => false, 'captionColor' => '#ffffff', 'captionCase' => 'sentence']);
});

it('rejects structurally invalid payloads without persisting', function (array $mutation): void {
    $cut = makeReadyCut($this->user);

    Livewire::test(Index::class, ['uuid' => $cut->uuid])
        ->call('saveEdit', array_replace(validSplitPayload(), $mutation))
        ->assertDispatched('toast', variant: 'danger');

    expect(VideoCutEdit::query()->count())->toBe(0);
})->with([
    'unknown mode' => [['mode' => 'diagonal']],
    'unknown keyframe mode' => [['keyframes' => [['t' => 0, 'mode' => 'diagonal', 'regions' => [['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]]]]],
    'keyframe without mode' => [['keyframes' => [['t' => 0, 'regions' => [['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]]]]],
    'region count mismatch' => [['keyframes' => [['t' => 0, 'mode' => 'trio', 'regions' => [['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1], ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]]]]],
    'empty keyframes' => [['keyframes' => []]],
    'too many keyframes' => [['keyframes' => array_map(
        fn (int $i): array => ['t' => $i, 'mode' => 'split', 'regions' => [['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1], ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]],
        range(0, 120),
    )]],
    'non numeric coords' => [['keyframes' => [['t' => 0, 'mode' => 'split', 'regions' => [['x' => 'a', 'y' => 0, 'w' => 1, 'h' => 1], ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]]]]],
    'missing source meta' => [['sourceMeta' => null]],
    'absurd duration' => [['sourceMeta' => ['width' => 1920, 'height' => 1080, 'duration' => 99999]]],
]);

it('saves keyframes with mixed framing modes', function (): void {
    $cut = makeReadyCut($this->user);

    Livewire::test(Index::class, ['uuid' => $cut->uuid])
        ->call('saveEdit', [
            'editId' => null,
            'mode' => 'vertical',
            'keyframes' => [
                ['t' => 0.0, 'mode' => 'vertical', 'regions' => [['x' => 0.34, 'y' => 0, 'w' => 0.32, 'h' => 1]]],
                ['t' => 5.0, 'mode' => 'split', 'regions' => [
                    ['x' => 0.1, 'y' => 0.0, 'w' => 0.4, 'h' => 0.5],
                    ['x' => 0.5, 'y' => 0.2, 'w' => 0.4, 'h' => 0.5],
                ]],
            ],
            'settings' => ['version' => 1, 'background' => '#000000'],
            'sourceMeta' => ['width' => 1920, 'height' => 1080, 'duration' => 15.0],
        ])
        ->assertDispatched('toast');

    $edit = VideoCutEdit::query()->sole();

    expect($edit->keyframes)->toHaveCount(2)
        ->and($edit->keyframes[0]['mode'])->toBe('vertical')
        ->and($edit->keyframes[0]['regions'])->toHaveCount(1)
        ->and($edit->keyframes[1]['mode'])->toBe('split')
        ->and($edit->keyframes[1]['regions'])->toHaveCount(2);
});

it('clamps out-of-bounds values and re-sorts keyframes on save', function (): void {
    $cut = makeReadyCut($this->user);

    $region = ['x' => 0.9, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0]; // x+w > 1
    Livewire::test(Index::class, ['uuid' => $cut->uuid])
        ->call('saveEdit', [
            'editId' => null,
            'mode' => 'vertical',
            'keyframes' => [
                ['t' => 999.0, 'mode' => 'vertical', 'regions' => [$region]], // t > duration
                ['t' => 5.0, 'mode' => 'vertical', 'regions' => [$region]],
            ],
            'settings' => ['background' => 'not-a-color'],
            'sourceMeta' => ['width' => 1920, 'height' => 1080, 'duration' => 60.0],
        ]);

    $edit = VideoCutEdit::query()->sole();
    // (float): o round-trip pelo JSON do banco pode devolver 5.0 como int 5.
    expect((float) $edit->keyframes[0]['t'])->toBe(5.0)
        ->and((float) $edit->keyframes[1]['t'])->toBe(60.0)
        ->and((float) $edit->keyframes[0]['regions'][0]['x'])->toBe(0.5)
        ->and($edit->settings)->toBe(['version' => 1, 'background' => '#000000', 'captions' => false, 'captionColor' => '#ffffff', 'captionCase' => 'sentence']);
});

it('loads the latest edit of the cut', function (): void {
    $cut = makeReadyCut($this->user);
    VideoCutEdit::factory()->create(['youtube_short_id' => null, 'video_cut_id' => $cut->id]);
    $latest = VideoCutEdit::factory()->create(['youtube_short_id' => null, 'video_cut_id' => $cut->id, 'mode' => 'split']);

    Livewire::test(Index::class, ['uuid' => $cut->uuid])
        ->assertSet('editId', $latest->id)
        ->assertViewHas('editorPayload', fn (array $payload): bool => $payload['editId'] === $latest->id
            && $payload['mode'] === 'split');
});

it('creates a valid edit from the factory', function (): void {
    $cut = makeReadyCut($this->user);
    $edit = VideoCutEdit::factory()->create(['video_cut_id' => $cut->id]);

    expect($edit->uuid)->not->toBe('')
        ->and($edit->mode)->toBe('vertical')
        ->and($edit->keyframes[0]['regions'])->toHaveCount(1)
        ->and($edit->videoCut)->toBeInstanceOf(VideoCut::class);
});
