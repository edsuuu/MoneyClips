<?php

declare(strict_types=1);

namespace App\Services\API\Wikipedia;

use App\Enums\StockAssetKindEnum;
use App\Enums\StockAssetLicenseEnum;
use App\Enums\StockAssetStatusEnum;
use App\Exceptions\WikipediaImageException;
use App\Models\StockAsset;
use App\Services\Assets\StockAssetData;
use App\Services\Assets\StockAssetService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class WikipediaImageService
{
    private const int WIDTH = 1000;

    public function __construct(private StockAssetService $stock) {}

    /**
     * Título exato → imagem principal do artigo, só com licença livre e sem
     * restrição de pessoa, marca ou insígnia. A mesma imagem já no estoque é
     * reaproveitada, pending inclusive (o render direto já usou ela uma vez
     * sem revisão); a que o dono recusou nunca volta.
     *
     * @throws WikipediaImageException
     * @throws Throwable
     */
    public function resolve(string $title, string $lang): StockAsset
    {
        $url = sprintf((string) config('services.wikipedia.api_url'), $lang);

        $page = $this->page($title, $url, [
            'titles' => $title,
            'redirects' => 1,
            'prop' => 'pageimages|pageprops',
            'piprop' => 'name',
            'pilicense' => 'free',
            'ppprop' => 'disambiguation',
        ]);

        if ($page === [] || isset($page['missing']) || isset($page['invalid'])) {
            throw WikipediaImageException::notFound($title);
        }

        if (! is_null(data_get($page, 'pageprops.disambiguation'))) {
            throw WikipediaImageException::disambiguation($title);
        }

        if (! is_string($page['pageimage'] ?? null) || $page['pageimage'] === '') {
            throw WikipediaImageException::withoutFreeImage($title);
        }

        $file = $this->page($title, $url, [
            'titles' => 'File:'.$page['pageimage'],
            'prop' => 'imageinfo',
            'iiprop' => 'url|size|extmetadata',
            'iiurlwidth' => self::WIDTH,
            'iiextmetadatafilter' => 'License|LicenseShortName|Artist|Restrictions',
        ]);

        $info = data_get($file, 'imageinfo.0');
        $info = is_array($info) ? $info : [];

        $imageUrl = (string) ($info['thumburl'] ?? $info['url'] ?? '');
        $sourceUrl = (string) ($info['descriptionurl'] ?? '');

        if ($imageUrl === '' || $sourceUrl === '') {
            throw WikipediaImageException::withoutFreeImage($title);
        }

        $restrictions = $this->meta($info, 'Restrictions');

        if ($restrictions !== '') {
            throw WikipediaImageException::restricted($title, $restrictions);
        }

        $license = $this->license($this->meta($info, 'License'));

        if (is_null($license)) {
            throw WikipediaImageException::notFree($title, $this->meta($info, 'LicenseShortName'));
        }

        $existing = StockAsset::query()->where('kind', StockAssetKindEnum::Image)->where('source_url', $sourceUrl)->first();

        if ($existing?->status === StockAssetStatusEnum::Disabled) {
            throw WikipediaImageException::rejected($title);
        }

        return $existing ?? $this->download($title, $imageUrl, $license, $sourceUrl, $this->meta($info, 'Artist'));
    }

    /**
     * @throws WikipediaImageException
     * @throws Throwable
     */
    private function download(string $title, string $imageUrl, StockAssetLicenseEnum $license, string $sourceUrl, string $author): StockAsset
    {
        $extension = mb_strtolower(pathinfo((string) parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (! in_array($extension, StockAssetKindEnum::Image->allowedExtensions(), true)) {
            throw WikipediaImageException::unsupportedFormat($title, $extension);
        }

        $response = $this->call($title, $imageUrl, []);
        $path = sprintf('%s/%s.%s', sys_get_temp_dir(), Str::uuid7(), $extension);

        file_put_contents($path, $response->body());

        try {
            return $this->stock->add($path, new StockAssetData(
                StockAssetKindEnum::Image,
                $license,
                [(string) Str::of($title)->ascii()->lower()->trim()],
                null,
                'wikipedia',
                $sourceUrl,
                $author === '' ? null : mb_substr($author, 0, 255),
                false,
                false,
                null,
            ));
        } finally {
            unlink($path);
        }
    }

    /**
     * @param  array<string, string|int>  $params
     * @return array<mixed>
     *
     * @throws WikipediaImageException
     */
    private function page(string $title, string $url, array $params): array
    {
        $response = $this->call($title, $url, [...$params, 'action' => 'query', 'format' => 'json', 'formatversion' => 2]);
        $page = $response->json('query.pages.0');

        return is_array($page) ? $page : [];
    }

    /**
     * @param  array<string, string|int>  $params
     *
     * @throws WikipediaImageException
     */
    private function call(string $title, string $url, array $params): Response
    {
        $userAgent = (string) config('services.wikipedia.user_agent');
        $context = ['url' => $url, 'headers' => ['User-Agent' => $userAgent], 'params' => $params];

        try {
            $response = Http::withUserAgent($userAgent)
                ->connectTimeout(5)
                ->timeout((int) config('services.wikipedia.timeout'))
                ->retry(2, 200, static fn (Throwable $exception): bool => $exception instanceof ConnectionException)
                ->get($url, $params);
        } catch (ConnectionException|RequestException $exception) {
            Log::channel('wikipedia')->error('[ERRO][Wikipedia] chamada falhou', [...$context, 'exception' => $exception]);

            throw WikipediaImageException::unavailable($title, $exception);
        }

        $isJson = str_contains((string) $response->header('Content-Type'), 'json');

        Log::channel('wikipedia')->info('[INFO][Wikipedia] chamada da API', [
            ...$context,
            'status' => $response->status(),
            'response' => $isJson ? $response->json() : sprintf('%d bytes', mb_strlen($response->body(), '8bit')),
        ]);

        return $response;
    }

    /**
     * @param  array<mixed>  $info
     */
    private function meta(array $info, string $field): string
    {
        $value = data_get($info, sprintf('extmetadata.%s.value', $field));

        return is_string($value) ? mb_trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value)))) : '';
    }

    private function license(string $code): ?StockAssetLicenseEnum
    {
        $code = mb_strtolower($code);

        return match (true) {
            $code === 'cc0' => StockAssetLicenseEnum::Cc0,
            $code === 'pd' || str_starts_with($code, 'pd-') => StockAssetLicenseEnum::PublicDomain,
            preg_match('/^cc-by-sa-\d/', $code) === 1 => StockAssetLicenseEnum::CcBySa,
            preg_match('/^cc-by-\d/', $code) === 1 => StockAssetLicenseEnum::CcBy,
            default => null,
        };
    }
}
