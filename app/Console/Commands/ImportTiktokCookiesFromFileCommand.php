<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Console\Command;
use JsonException;

final class ImportTiktokCookiesFromFileCommand extends Command
{
    /** @var string */
    protected $signature = 'tiktok:import-cookies-from-file
        {--name= : nome da conta TikTok (default: env TIKTOK_ACCOUNT_NAME)}
        {--path= : caminho do JSON (default: MicroServices/TikTokUploader/cookies/{name}.json)}';

    /** @var string */
    protected $description = 'Importa cookies do TikTok do arquivo JSON pro social_accounts (one-shot).';

    public function handle(): int
    {
        $name = mb_trim((string) ($this->option('name') ?? config('services.tiktok_post.account_name')));
        if ($name === '') {
            $this->error('Informe --name=<handle> ou configure TIKTOK_ACCOUNT_NAME.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('path') ?? base_path(sprintf('MicroServices/TikTokUploader/cookies/%s.json', $name)));
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Arquivo não encontrado ou ilegível: '.$path);

            return self::FAILURE;
        }

        $raw = (string) file_get_contents($path);
        try {
            /** @var array<int, array<string, mixed>> $cookies */
            $cookies = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            $this->error('JSON inválido: '.$jsonException->getMessage());

            return self::FAILURE;
        }

        $admin = User::query()->orderBy('id')->first();
        $userId = $admin instanceof User ? max(0, (int) $admin->id) : null;

        $account = SocialAccount::query()
            ->where('platform', 'tiktok')
            ->where('name', $name)
            ->first();

        if (! $account instanceof SocialAccount) {
            $account = new SocialAccount;
            $account->platform = 'tiktok';
            $account->name = $name;
            $account->user_id = $userId;
            $account->is_active = true;
        }

        $account->cookies = $cookies;
        $account->session_status = SocialAccount::SESSION_UNKNOWN;
        $account->save();

        $this->info(sprintf(
            'OK — social_accounts (platform=tiktok, name=%s) atualizada com %d cookies.',
            $name,
            count($cookies),
        ));

        return self::SUCCESS;
    }
}
