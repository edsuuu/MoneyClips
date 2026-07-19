<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MigrateTiktokPostsCommand extends Command
{
    /** @var string */
    protected $signature = 'posts:migrate-tiktok {--chunk=500 : Linhas por batch}';

    /** @var string */
    protected $description = 'Migra tiktok_posts para social_posts (platform=tiktok). Idempotente, não destrutivo.';

    public function handle(): int
    {
        if (! Schema::hasTable('tiktok_posts')) {
            $this->info('Tabela tiktok_posts não existe — nada a migrar.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('social_posts')) {
            $this->error('Tabela social_posts não existe. Rode `php artisan migrate` primeiro.');

            return self::FAILURE;
        }

        $sourceTotal = (int) DB::table('tiktok_posts')->count();
        if ($sourceTotal === 0) {
            $this->info('tiktok_posts está vazia — nada a migrar.');

            return self::SUCCESS;
        }

        $alreadyCopied = (int) DB::table('social_posts')->where('platform', 'tiktok')->count();
        $this->info(sprintf('Origem: %d linhas em tiktok_posts.', $sourceTotal));
        $this->info(sprintf('Destino: %d linhas em social_posts (platform=tiktok) já existentes.', $alreadyCopied));

        $copied = 0;
        $skipped = 0;
        $chunkSize = (int) $this->option('chunk');

        DB::table('tiktok_posts')->orderBy('id')->chunkById($chunkSize, function ($rows) use (&$copied, &$skipped): void {
            /** @var list<string> $uuids */
            $uuids = collect($rows)->pluck('uuid')->map(static fn ($u): string => (string) $u)->all();
            /** @var list<string> $existing */
            $existing = DB::table('social_posts')->whereIn('uuid', $uuids)->pluck('uuid')->map(static fn ($u): string => (string) $u)->all();
            $existingSet = array_flip($existing);

            $payload = [];
            foreach ($rows as $row) {
                $uuid = (string) $row->uuid;
                if (isset($existingSet[$uuid])) {
                    $skipped++;

                    continue;
                }

                $payload[] = [
                    'platform' => 'tiktok',
                    'uuid' => $row->uuid,
                    'youtube_id' => $row->youtube_id,
                    'video_key' => $row->video_key,
                    'title' => $row->title,
                    'hashtags' => $row->hashtags,
                    'account_name' => $row->account_name,
                    'status' => $row->status,
                    'error' => $row->error,
                    'requested_at' => $row->requested_at,
                    'started_at' => $row->started_at,
                    'posted_at' => $row->posted_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
                $copied++;
            }

            if ($payload !== []) {
                DB::table('social_posts')->insert($payload);
            }
        });

        $this->newLine();
        $this->info(sprintf('Migração OK: %d novas linhas copiadas, %d já existiam (puladas).', $copied, $skipped));
        $this->line('Próximo passo: rode a migration de drop para remover tiktok_posts (depois de validar).');

        return self::SUCCESS;
    }
}
