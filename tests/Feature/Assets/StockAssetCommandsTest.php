<?php

declare(strict_types=1);

use App\Enums\StockAssetEmotionEnum;
use App\Enums\StockAssetStatusEnum;
use App\Models\StockAsset;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('s3');
    Process::fake();
});

function tempAsset(string $name): string
{
    $path = sys_get_temp_dir().'/'.uniqid().'-'.$name;
    file_put_contents($path, 'x');

    return $path;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pendingAsset(array $overrides = []): StockAsset
{
    return StockAsset::query()->create($overrides + [
        'id' => (string) Str::uuid7(),
        'kind' => 'sfx',
        'tags' => ['boom'],
        'license' => 'cc0',
        'source' => 'manual',
        'storage_key' => 'assets/sfx/x.mp3',
    ]);
}

it('uploads the file to assets/<kind>/<uuid>.<ext> and registers it as pending', function (): void {
    $this->artisan('assets:add-file', ['path' => tempAsset('boom.mp3'), '--kind' => 'sfx', '--license' => 'cc0', '--tags' => 'Explosão, boom,boom', '--source-url' => 'https://x.test/1'])
        ->assertSuccessful();

    $asset = StockAsset::query()->sole();

    expect($asset->status)->toBe(StockAssetStatusEnum::Pending)
        ->and($asset->tags)->toBe(['explosao', 'boom'])
        ->and($asset->storage_key)->toBe(sprintf('assets/sfx/%s.mp3', $asset->id))
        ->and($asset->source_url)->toBe('https://x.test/1');
    Storage::disk('s3')->assertExists($asset->storage_key);
});

it('fills duration, size and author from ffprobe (sfx, meme_clip) and getimagesize (image)', function (): void {
    Process::fake([
        '*' => Process::sequence()
            ->push('{"streams":[],"format":{"duration":"3.5"}}')
            ->push('{"streams":[{"width":720,"height":1280}],"format":{"duration":"3.5"}}'),
    ]);
    $png = tempAsset('a.png');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAYAAAC56t6BAAAAEklEQVR4nGP4z8Dwn4GBgQEAFAUCAU2a6TQAAAAASUVORK5CYII=', true));

    $this->artisan('assets:add-file', ['path' => tempAsset('b.mp3'), '--kind' => 'sfx', '--license' => 'cc0', '--author' => 'Fulano'])->assertSuccessful();
    $this->artisan('assets:add-file', ['path' => tempAsset('c.mp4'), '--kind' => 'meme_clip', '--license' => 'own_risk'])->assertSuccessful();
    $this->artisan('assets:add-file', ['path' => $png, '--kind' => 'image', '--license' => 'cc0'])->assertSuccessful();

    $sfx = StockAsset::query()->where('kind', 'sfx')->sole();
    $clip = StockAsset::query()->where('kind', 'meme_clip')->sole();
    $image = StockAsset::query()->where('kind', 'image')->sole();

    expect([$sfx->duration_ms, $sfx->width, $sfx->height, $sfx->author])->toBe([3500, null, null, 'Fulano'])
        ->and([$clip->duration_ms, $clip->width, $clip->height, $clip->author])->toBe([3500, 720, 1280, null])
        ->and([$image->duration_ms, $image->width, $image->height])->toBe([null, 2, 3]);
});

it('keeps the metadata null and still adds the asset when the probe fails', function (): void {
    Process::fake(['*' => Process::result('', 'boom', 1)]);

    $this->artisan('assets:add-file', ['path' => tempAsset('b.mp3'), '--kind' => 'sfx', '--license' => 'cc0'])->assertSuccessful();

    expect(StockAsset::query()->sole()->duration_ms)->toBeNull();
});

it('rejects a wrong extension and a meme without own_risk', function (): void {
    $this->artisan('assets:add-file', ['path' => tempAsset('a.txt'), '--kind' => 'sfx', '--license' => 'cc0'])->assertFailed();
    $this->artisan('assets:add-file', ['path' => tempAsset('a.png'), '--kind' => 'meme_sticker', '--license' => 'cc0'])->assertFailed();

    expect(StockAsset::query()->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('adds every valid file of a directory and skips the rest', function (): void {
    $dir = sys_get_temp_dir().'/'.uniqid('assets');
    mkdir($dir);
    file_put_contents($dir.'/a.png', 'x');
    file_put_contents($dir.'/b.webp', 'x');
    file_put_contents($dir.'/c.txt', 'x');

    $this->artisan('assets:add-dir', ['path' => $dir, '--kind' => 'meme_sticker', '--license' => 'own_risk', '--emotion' => 'deboche'])
        ->assertFailed();

    expect(StockAsset::query()->count())->toBe(2)
        ->and(StockAsset::query()->first()->emotion)->toBe(StockAssetEmotionEnum::Mockery);
});

it('reviews pending assets, warning about risk, and approves or disables', function (): void {
    $risky = pendingAsset(['kind' => 'meme_clip', 'license' => 'own_risk', 'has_audio' => true, 'risk_note' => 'trecho de filme']);
    $plain = pendingAsset();
    $skipped = pendingAsset();

    $this->artisan('assets:review')
        ->expectsOutputToContain('RISCO')
        ->expectsChoice('Aceita o risco e aprova?', 'aprovar', ['aprovar', 'recusar', 'pular'])
        ->expectsQuestion('Tags finais (vírgula)', 'Piada, Filme')
        ->expectsChoice('Emoção', 'vitoria', array_column(StockAssetEmotionEnum::cases(), 'value'))
        ->expectsChoice('Aprovar?', 'recusar', ['aprovar', 'recusar', 'pular'])
        ->expectsChoice('Aprovar?', 'pular', ['aprovar', 'recusar', 'pular'])
        ->assertSuccessful();

    expect($risky->refresh()->status)->toBe(StockAssetStatusEnum::Approved)
        ->and($risky->tags)->toBe(['piada', 'filme'])
        ->and($risky->emotion)->toBe(StockAssetEmotionEnum::Victory)
        ->and($risky->approved_at)->not->toBeNull()
        ->and($plain->refresh()->status)->toBe(StockAssetStatusEnum::Disabled)
        ->and($skipped->refresh()->status)->toBe(StockAssetStatusEnum::Pending);
});

it('disables an asset by id', function (): void {
    $asset = pendingAsset(['status' => 'approved']);

    $this->artisan('assets:disable', ['id' => $asset->id])->assertSuccessful();
    $this->artisan('assets:disable', ['id' => 'nope'])->assertFailed();

    expect($asset->refresh()->status)->toBe(StockAssetStatusEnum::Disabled);
});
