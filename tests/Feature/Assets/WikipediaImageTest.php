<?php

declare(strict_types=1);

use App\Enums\StockAssetLicenseEnum;
use App\Enums\StockAssetStatusEnum;
use App\Exceptions\WikipediaImageException;
use App\Models\StockAsset;
use App\Services\API\Wikipedia\WikipediaImageService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('s3');
});

function wikipediaPage(array $overrides = []): array
{
    return ['pageid' => 1, 'title' => 'Fusca', 'pageimage' => 'Fusca_azul.png', ...$overrides];
}

function wikipediaFile(array $metadata = []): array
{
    return [
        'title' => 'Arquivo:Fusca_azul.png',
        'missing' => true,
        'imagerepository' => 'shared',
        'imageinfo' => [[
            'thumburl' => 'https://upload.wikimedia.org/thumb/1000px-Fusca_azul.png',
            'url' => 'https://upload.wikimedia.org/Fusca_azul.png',
            'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Fusca_azul.png',
            'extmetadata' => [
                'License' => ['value' => 'cc-by-sa-4.0'],
                'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                'Artist' => ['value' => '<a href="//commons.wikimedia.org/wiki/User:Fulano">Fulano  de Tal</a>'],
                ...$metadata,
            ],
        ]],
    ];
}

function fakeWikipedia(array $page, array $file): void
{
    Http::fake([
        'upload.wikimedia.org/*' => Http::response((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAYAAAC56t6BAAAAEklEQVR4nGP4z8Dwn4GBgQEAFAUCAU2a6TQAAAAASUVORK5CYII=', true), 200, ['Content-Type' => 'image/png']),
        '*.wikipedia.org/*' => fn (Request $request) => Http::response(['query' => ['pages' => [str_contains($request->url(), 'imageinfo') ? $file : $page]]]),
    ]);
}

it('stores the free main image of the article as a pending stock image with license and author', function (): void {
    fakeWikipedia(wikipediaPage(), wikipediaFile());

    $asset = resolve(WikipediaImageService::class)->resolve('Fusca', 'pt');

    expect($asset->status)->toBe(StockAssetStatusEnum::Pending)
        ->and($asset->license)->toBe(StockAssetLicenseEnum::CcBySa)
        ->and($asset->author)->toBe('Fulano de Tal')
        ->and($asset->source_url)->toBe('https://commons.wikimedia.org/wiki/File:Fusca_azul.png')
        ->and([$asset->width, $asset->height])->toBe([2, 3])
        ->and($asset->storage_key)->toBe(sprintf('assets/image/%s.png', $asset->id))
        ->and($asset->credit())->toBe('Imagem: Fulano de Tal, CC BY-SA https://commons.wikimedia.org/wiki/File:Fusca_azul.png');
    Storage::disk('s3')->assertExists($asset->storage_key);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://pt.wikipedia.org/w/api.php')
        && $request->hasHeader('User-Agent', (string) config('services.wikipedia.user_agent')));
});

it('reuses the stored image, pending or approved, and never brings back a disabled one', function (): void {
    fakeWikipedia(wikipediaPage(), wikipediaFile());
    $service = resolve(WikipediaImageService::class);
    $first = $service->resolve('Fusca', 'pt');

    expect($service->resolve('Fusca', 'pt')->id)->toBe($first->id)
        ->and(StockAsset::query()->count())->toBe(1);
    Http::assertSentCount(5);

    $first->update(['status' => StockAssetStatusEnum::Disabled]);

    expect(fn () => $service->resolve('Fusca', 'pt'))->toThrow(WikipediaImageException::class, 'recusada pelo dono');
});

it('refuses what is not a free, unrestricted, unambiguous image', function (array $page, array $file, string $message): void {
    fakeWikipedia($page, $file);

    expect(fn () => resolve(WikipediaImageService::class)->resolve('Fusca', 'pt'))->toThrow(WikipediaImageException::class, $message);
    expect(StockAsset::query()->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
})->with([
    'licença não livre' => [wikipediaPage(), wikipediaFile(['License' => ['value' => 'gfdl'], 'LicenseShortName' => ['value' => 'GFDL']]), 'licença não livre (GFDL)'],
    'desambiguação' => [wikipediaPage(['pageprops' => ['disambiguation' => '']]), wikipediaFile(), 'página de desambiguação'],
    'Restrictions' => [wikipediaPage(), wikipediaFile(['Restrictions' => ['value' => 'personality']]), 'imagem com restrição (personality)'],
    'sem imagem livre' => [wikipediaPage(['pageimage' => null]), wikipediaFile(), 'artigo sem imagem livre'],
    'artigo inexistente' => [wikipediaPage(['missing' => true]), wikipediaFile(), 'artigo inexistente'],
]);

it('gives up with a warning-ready error when wikipedia times out', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    expect(fn () => resolve(WikipediaImageService::class)->resolve('Fusca', 'pt'))->toThrow(WikipediaImageException::class, 'Wikipedia indisponível');
    expect(StockAsset::query()->count())->toBe(0);
});
