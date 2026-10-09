<?php

declare(strict_types=1);

namespace App\Services\CutEdit;

use App\Enums\StockAssetKindEnum;
use App\Enums\StockAssetStatusEnum;
use App\Models\StockAsset;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use JsonException;

final readonly class CutEditAssetService
{
    private const array FIELDS = [
        'memes' => StockAssetKindEnum::MemeSticker,
        'meme_clips' => StockAssetKindEnum::MemeClip,
        'emoji' => StockAssetKindEnum::Emoji,
        'sfx' => StockAssetKindEnum::Sfx,
    ];

    private const string PROMPT = 'prompts/cut-edit.md';

    private const string ASSETS_PROMPT = 'prompts/cut-edit-assets.md';

    private const string SCHEMA = 'prompts/cut-edit.schema.json';

    private const int RECENT_CLIPS = 2;

    /**
     * Aprovados de cada campo, menos os que os 2 clips editados por último do
     * mesmo vídeo usaram: é o "nunca no mesmo padrão" entre Shorts seguidos.
     * Vídeo-meme sem duração fica fora porque a janela dele é a duração.
     *
     * @return array<string, array<string, StockAsset>>
     */
    public function available(VideoCut $cut): array
    {
        $recent = $this->recentlyUsed($cut);
        $available = array_fill_keys(array_keys(self::FIELDS), []);

        $assets = StockAsset::query()
            ->where('status', StockAssetStatusEnum::Approved)
            ->whereIn('kind', array_values(self::FIELDS))
            ->whereNotIn('id', $recent)
            ->orderBy('id')
            ->get();

        foreach ($assets as $asset) {
            if ($asset->kind === StockAssetKindEnum::MemeClip && is_null($asset->duration_ms)) {
                continue;
            }

            $field = (string) array_search($asset->kind, self::FIELDS, true);
            $available[$field][$asset->id] = $asset;
        }

        return $available;
    }

    /**
     * ponytail: o catálogo inteiro vai no prompt (~45 itens ≈ 2k tokens).
     * Acima de uns 500 itens o prompt estoura e entra busca por tag antes da IA.
     *
     * @param  array<string, array<string, StockAsset>>  $assets
     *
     * @throws FileNotFoundException
     */
    public function prompt(array $assets): string
    {
        $prompt = File::get(resource_path(self::PROMPT));

        if (array_filter($assets) === []) {
            return $prompt;
        }

        $lines = [$prompt, File::get(resource_path(self::ASSETS_PROMPT))];

        foreach ($assets as $field => $items) {
            if ($items === []) {
                continue;
            }

            $lines[] = sprintf('#### `%s`', $field);

            foreach ($items as $asset) {
                $lines[] = sprintf(
                    '%s | %s | %s | %s',
                    $asset->id,
                    $asset->emotion->value ?? '-',
                    implode(', ', $asset->tags),
                    is_null($asset->duration_ms) ? '-' : sprintf('%.1fs', $asset->duration_ms / 1000),
                );
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Campo sem asset aprovado sai do schema; com asset, o `asset_id` vira enum
     * dos ids do catálogo.
     *
     * @param  array<string, array<string, StockAsset>>  $assets
     *
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function schema(array $assets): string
    {
        $schema = (array) json_decode(File::get(resource_path(self::SCHEMA)), true, 512, JSON_THROW_ON_ERROR);

        foreach (array_keys(self::FIELDS) as $field) {
            if (empty($assets[$field])) {
                Arr::forget($schema, 'properties.'.$field);

                continue;
            }

            Arr::set($schema, sprintf('properties.%s.items.properties.asset_id.enum', $field), array_keys($assets[$field]));
        }

        return json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    private function recentlyUsed(VideoCut $cut): array
    {
        $edits = VideoCutEdit::query()
            ->whereNotNull('spec')
            ->where('video_cut_id', '!=', $cut->id)
            ->whereRelation('videoCut', 'video_id', $cut->video_id)
            ->latest('id')
            ->get(['video_cut_id', 'spec']);

        $clips = [];
        $ids = [];

        foreach ($edits as $edit) {
            if (isset($clips[$edit->video_cut_id])) {
                continue;
            }

            if (count($clips) === self::RECENT_CLIPS) {
                break;
            }

            $clips[$edit->video_cut_id] = true;

            foreach (array_keys(self::FIELDS) as $field) {
                foreach ($edit->spec[$field] ?? [] as $item) {
                    $ids[] = $item['asset_id'];
                }
            }
        }

        return $ids;
    }
}
