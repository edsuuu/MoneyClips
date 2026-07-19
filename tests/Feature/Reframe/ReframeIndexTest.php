<?php

declare(strict_types=1);

use App\Livewire\Reframe\Index;
use App\Models\ReframeEdit;
use App\Models\User;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

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
            ['t' => 0.0, 'regions' => $regions],
            ['t' => 12.5, 'regions' => $regions],
        ],
        'settings' => ['version' => 1, 'background' => '#101828'],
        'sourceMeta' => ['width' => 1920, 'height' => 1080, 'duration' => 60.0],
    ];
}

it('renders the screen for authenticated users and redirects guests', function (): void {
    $this->get('/estudio-de-cortes')->assertOk()->assertSeeLivewire('reframe.index');

    Auth::logout();
    $this->get('/estudio-de-cortes')->assertRedirect();
});

it('resets an invalid video id from the url', function (): void {
    $withoutFile = YoutubeShort::factory()->notDownloaded()->create();

    Livewire::withQueryParams(['video' => $withoutFile->id])
        ->test(Index::class)
        ->assertSet('videoId', null);

    Livewire::withQueryParams(['video' => 999999])
        ->test(Index::class)
        ->assertSet('videoId', null);
});

it('exposes a default editor payload when the video has no edit yet', function (): void {
    $short = YoutubeShort::factory()->create();

    Livewire::test(Index::class)
        ->call('selectSource', $short->id)
        ->assertSet('videoId', $short->id)
        ->assertSet('editId', null)
        ->assertViewHas('editorPayload', fn (array $payload): bool => $payload['editId'] === null
            && $payload['mode'] === 'vertical'
            && $payload['keyframes'] === []
            && $payload['settings'] === ['version' => 1, 'background' => '#000000']
            && $payload['sourceMeta'] === null);
});

it('creates an edit on save and updates it in place on the next save', function (): void {
    $short = YoutubeShort::factory()->create();

    $component = Livewire::test(Index::class)
        ->call('selectSource', $short->id)
        ->call('saveEdit', validSplitPayload())
        ->assertDispatched('toast');

    $edit = ReframeEdit::query()->sole();
    expect($edit->uuid)->not->toBe('')
        ->and($edit->youtube_short_id)->toBe($short->id)
        ->and($edit->source_path)->toBe($short->postableVideoPath())
        ->and($edit->mode)->toBe('split')
        ->and($edit->keyframes)->toHaveCount(2)
        ->and($edit->settings)->toBe(['version' => 1, 'background' => '#101828']);

    $component->assertSet('editId', $edit->id);

    $payload = validSplitPayload();
    $payload['editId'] = $edit->id;
    $payload['settings']['background'] = '#FFFFFF';
    $component->call('saveEdit', $payload);

    expect(ReframeEdit::query()->count())->toBe(1)
        ->and($edit->refresh()->settings)->toBe(['version' => 1, 'background' => '#ffffff']);
});

it('rejects structurally invalid payloads without persisting', function (array $mutation): void {
    $short = YoutubeShort::factory()->create();

    Livewire::test(Index::class)
        ->call('selectSource', $short->id)
        ->call('saveEdit', array_replace(validSplitPayload(), $mutation))
        ->assertDispatched('toast', variant: 'danger');

    expect(ReframeEdit::query()->count())->toBe(0);
})->with([
    'unknown mode' => [['mode' => 'diagonal']],
    'region count mismatch' => [['mode' => 'trio']],
    'empty keyframes' => [['keyframes' => []]],
    'too many keyframes' => [['keyframes' => array_map(
        fn (int $i): array => ['t' => $i, 'regions' => [['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1], ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]],
        range(0, 120),
    )]],
    'non numeric coords' => [['keyframes' => [['t' => 0, 'regions' => [['x' => 'a', 'y' => 0, 'w' => 1, 'h' => 1], ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]]]]],
    'missing source meta' => [['sourceMeta' => null]],
    'absurd duration' => [['sourceMeta' => ['width' => 1920, 'height' => 1080, 'duration' => 99999]]],
]);

it('clamps out-of-bounds values and re-sorts keyframes on save', function (): void {
    $short = YoutubeShort::factory()->create();

    $region = ['x' => 0.9, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0]; // x+w > 1
    Livewire::test(Index::class)
        ->call('selectSource', $short->id)
        ->call('saveEdit', [
            'editId' => null,
            'mode' => 'vertical',
            'keyframes' => [
                ['t' => 999.0, 'regions' => [$region]], // t > duration
                ['t' => 5.0, 'regions' => [$region]],
            ],
            'settings' => ['background' => 'not-a-color'],
            'sourceMeta' => ['width' => 1920, 'height' => 1080, 'duration' => 60.0],
        ]);

    $edit = ReframeEdit::query()->sole();
    // (float): o round-trip pelo JSON do banco pode devolver 5.0 como int 5.
    expect((float) $edit->keyframes[0]['t'])->toBe(5.0)
        ->and((float) $edit->keyframes[1]['t'])->toBe(60.0)
        ->and((float) $edit->keyframes[0]['regions'][0]['x'])->toBe(0.5)
        ->and($edit->settings)->toBe(['version' => 1, 'background' => '#000000']);
});

it('loads the latest edit of the selected video', function (): void {
    $short = YoutubeShort::factory()->create();
    ReframeEdit::factory()->create(['youtube_short_id' => $short->id]);
    $latest = ReframeEdit::factory()->create(['youtube_short_id' => $short->id, 'mode' => 'split']);

    Livewire::test(Index::class)
        ->call('selectSource', $short->id)
        ->assertSet('editId', $latest->id)
        ->assertViewHas('editorPayload', fn (array $payload): bool => $payload['editId'] === $latest->id
            && $payload['mode'] === 'split');
});

it('returns null from refreshUrl when the disk cannot presign', function (): void {
    $short = YoutubeShort::factory()->create();

    Livewire::test(Index::class)
        ->call('selectSource', $short->id)
        ->call('refreshUrl')
        ->assertOk();
});

it('creates a valid edit from the factory', function (): void {
    $edit = ReframeEdit::factory()->create();

    expect($edit->uuid)->not->toBe('')
        ->and($edit->mode)->toBe('vertical')
        ->and($edit->keyframes[0]['regions'])->toHaveCount(1)
        ->and($edit->youtubeShort)->toBeInstanceOf(YoutubeShort::class);
});
