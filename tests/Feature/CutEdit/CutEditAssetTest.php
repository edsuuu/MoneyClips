<?php

declare(strict_types=1);

use App\Enums\VideoCutStatusEnum;
use App\Models\StockAsset;
use App\Models\Video;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use App\Services\CutEdit\CutEditAssetService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function stockAsset(string $kind, array $overrides = []): StockAsset
{
    $id = (string) Str::uuid7();

    return StockAsset::query()->create($overrides + [
        'id' => $id,
        'kind' => $kind,
        'tags' => ['susto'],
        'license' => 'own_risk',
        'source' => 'manual',
        'storage_key' => sprintf('assets/%s/%s.png', $kind, $id),
        'status' => 'approved',
    ]);
}

function stockCut(Video $video): VideoCut
{
    return $video->cuts()->create(['start_seconds' => 0, 'end_seconds' => 90, 'status' => VideoCutStatusEnum::Ready]);
}

function stockEdit(VideoCut $cut, StockAsset $asset): void
{
    VideoCutEdit::factory()->create(['video_cut_id' => $cut->id, 'spec' => ['memes' => [['asset_id' => $asset->id, 'key' => $asset->storage_key, 't' => [1.0, 2.0], 'has_audio' => false]]]]);
}

it('builds each asset enum from the approved assets of its kind', function (): void {
    $memes = [stockAsset('meme_sticker', ['emotion' => 'espanto']), stockAsset('meme_sticker')];
    $sound = stockAsset('sfx', ['duration_ms' => 1200, 'storage_key' => 'assets/sfx/boom.mp3']);
    $pending = stockAsset('meme_sticker', ['status' => 'pending']);
    stockAsset('emoji', ['status' => 'disabled']);
    stockAsset('image');
    stockAsset('meme_clip');

    $service = resolve(CutEditAssetService::class);
    $assets = $service->available(stockCut(Video::factory()->ready()->create()));
    $schema = json_decode($service->schema($assets), true, 512, JSON_THROW_ON_ERROR);
    $prompt = $service->prompt($assets);
    $memeIds = collect($memes)->pluck('id')->sort()->values()->all();

    expect($schema['properties']['memes']['items']['properties']['asset_id']['enum'])->toBe($memeIds)
        ->and($schema['properties']['sfx']['items']['properties']['asset_id']['enum'])->toBe([$sound->id])
        ->and($schema['properties'])->not->toHaveKeys(['emoji', 'meme_clips'])
        ->and($schema['required'])->not->toContain('memes')
        ->and($prompt)->toContain('#### `memes`', $memes[0]->id.' | espanto | susto | -', '#### `sfx`', $sound->id.' | - | susto | 1.2s')
        ->and($prompt)->not->toContain($pending->id, '#### `emoji`', '#### `meme_clips`');
});

it('leaves every asset field out of the schema and the prompt without approved assets', function (): void {
    stockAsset('meme_sticker', ['status' => 'pending']);

    $service = resolve(CutEditAssetService::class);
    $assets = $service->available(stockCut(Video::factory()->ready()->create()));
    $schema = json_decode($service->schema($assets), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['properties'])->not->toHaveKeys(['memes', 'meme_clips', 'emoji', 'sfx'])
        ->and($schema['properties'])->toHaveKeys(['captions', 'notes', 'punches'])
        ->and($service->prompt($assets))->toBe(File::get(resource_path('prompts/cut-edit.md')));
});

it('skips the assets of the latest edit of the 2 clips edited before on the same video', function (): void {
    $video = Video::factory()->ready()->create();
    [$oldest, $older, $latest, $current] = [stockCut($video), stockCut($video), stockCut($video), stockCut($video)];
    [$first, $stale, $second, $third, $elsewhere] = [stockAsset('meme_sticker'), stockAsset('meme_sticker'), stockAsset('meme_sticker'), stockAsset('meme_sticker'), stockAsset('meme_sticker')];
    stockEdit($oldest, $first);
    stockEdit($latest, $stale);
    stockEdit($older, $second);
    stockEdit($latest, $third);
    stockEdit(stockCut(Video::factory()->ready()->create()), $elsewhere);

    $available = resolve(CutEditAssetService::class)->available($current);

    expect(array_keys($available['memes']))->toEqualCanonicalizing([$first->id, $stale->id, $elsewhere->id]);
});
