# MoneyClips

Plataforma de **auto-postagem de Shorts** em YouTube + TikTok. Laravel
orquestra; microserviços fazem o trabalho pesado (download, upload via
Playwright). Repositório anterior (`generate-clips-laravel`) tinha pipeline
de cortes de vídeo longo — foi removido; agora o foco é só auto-postagem.

## Stack

- **PHP 8.4+ / Laravel 12+** (`bootstrap/app.php`)
- **Livewire 3 + Tailwind 4 + Vite** — kit próprio de componentes Blade em
  `resources/views/components/ui/` (sem Flux UI). Toasts: trait
  `App\Livewire\Concerns\WithToasts` → evento consumido por
  `components/ui/toasts.blade.php`.
- **MySQL** (`DB_CONNECTION=mysql`) + **fila em banco** (`QUEUE_CONNECTION=database`, hoje sem `ShouldQueue` ativos)
- **MinIO** (S3-compatível) — disk `s3`, bucket `videos`
- Qualidade: **PHPStan/Larastan**, **Pint**, **Rector** (CI roda `composer check`)

## Domínio único: auto-postagem de Shorts

Pipeline:

```
download-shorts (FastAPI) → MinIO + youtube_shorts (estoque)
  → cron Laravel (schedule:run) → AutoPostDispatcher
     → YoutubePoster (síncrono, Data API)
     → TiktokPoster (assíncrono → tiktok-uploader → webhook callback)
```

### Auto-postagem (`App\Services\AutoPost\`)

- `WindowSchedule` — 5 slots/dia em horas fixas (`SLOT_HOURS = [9,12,15,18,21]`,
  fuso América/São_Paulo). Minuto sorteado por dia/hora via `crc32(date:hour) % 60`
  → muda toda semana (mesma 2ª-feira da próxima semana = minuto diferente).
- `AutoPostDispatcher` — orquestra: pega lock da janela (`Cache::add`),
  reserva 1 Short com `StockReservation`, roda os Posters habilitados.
- `Posters/` — `PosterContract`, `PosterResult` (DTO), `YoutubePoster`,
  `TiktokPoster`. Cada Poster lê `users.auto_post_{platform}_enabled` (admin
  id=1) pra decidir se está ativo. Falhas vão pro Discord via `DiscordNotifier`.
- `StockReservation` — `reserveNext()` atômico em `youtube_shorts.dispatched_at`;
  `warnIfLowStock()` avisa Discord 1×/dia.

### Cron + alertas

```
* * * * * cd /var/www/projects/MoneyClips && php artisan schedule:run
```

`routes/console.php` registra:
- `auto-post-social` — every minute, `->when(WindowSchedule::isDueWindow())`
  filtra pro minuto sorteado.
- `auto-post-check-missed` — every 10 min, varre slots passados sem
  postagem (lookback 6h) e alerta Discord 1×/slot via `Cache::add` dedupe.

### YouTube

- `App\Services\Youtube\ShortsPoster` — upload resumível na YouTube Data API v3
  (HTTP puro, sem google/apiclient). Marca `posted_youtube_at` +
  `youtube_video_id` no Short.
- Credenciais: `social_accounts` (platform=`youtube`, OAuth Google).
  Refresh automático via `YoutubeTokenRefresher`. Connect em `/social-accounts`.
- Toggle pra desligar sem deploy: switch no `/agenda` ou alterar
  `users.auto_post_youtube_enabled` direto no banco.

### TikTok

- **Sem OAuth oficial** — autenticação por cookies do Playwright.
- Cookies vivem **criptografados no banco**: `social_accounts.cookies`
  (cast `encrypted:array`). Colunas extras: `cookies_last_validated_at`,
  `session_status` (`valid`/`invalid`/`unknown`).
- `App\Services\TikTok\TiktokPostService::queuePost()` lê os cookies do banco
  e envia no payload do `POST /posts` ao microserviço uploader. Não tem mais
  arquivo no filesystem como fonte da verdade.
- `App\Http\Controllers\TiktokPostCallbackController` recebe o webhook do
  uploader: atualiza ledger (social_posts), salva `refreshed_cookies` se
  vierem, propaga `session_status`. Quando `session_status='invalid'`,
  dispara Discord error pedindo ação manual.
- `TiktokPoster` curto-circuita postagens quando `session_status='invalid'`
  pra evitar flag de spam.
- UI pra colar JSON novo de cookies: `<livewire:settings.tiktok-cookies />`
  embutido em `/settings/accounts`.

## Banco de dados (visão geral)

| Tabela | Papel |
| --- | --- |
| `users` | login Google OAuth; colunas `auto_post_{youtube,tiktok}_enabled` |
| `social_accounts` | credenciais por plataforma (OAuth do YT, cookies do TT) |
| `youtube_shorts` | estoque de Shorts baixados; ciclo de vida (`dispatched_at`, `posted_youtube_at`, `posted_tiktok_at`) |
| `social_posts` | ledger genérico de postagens (`platform` discrimina) |
| `cache` | locks da janela (`Cache::add` do `AutoPostDispatcher`) |

`social_posts` substituiu o antigo `tiktok_posts` — adicionar Instagram/X
no futuro é só usar uma nova string em `platform`.

## Telas

| Rota | Componente | Função |
| --- | --- | --- |
| `/agenda` | `App\Livewire\Schedule\Index` | grade 7×5 dos slots da semana + toggles YT/TT + "Forçar agora" |
| `/downloads` | `App\Livewire\Downloads\Index` | estoque com tabs (disponíveis/fila/postados/falhas) + modais de novo download + postagem instantânea |
| `/settings/accounts` | `App\Livewire\Settings\Accounts` + `TiktokCookies` | OAuth YouTube + textarea de cookies TikTok |
| `/microservices` | `App\Livewire\Microservices\Index` | health dos serviços (download-shorts, tiktok-uploader) + logs |
| `/dashboard` | view | 4 cards de métricas (estoque, postados YT/TT, contas) |

## Comandos artisan

| Comando | O que faz |
| --- | --- |
| `youtube:download-shorts <canal> [--limit=N]` | baixa Shorts do canal pra MinIO + banco (via download-shorts microservice) |
| `auto-post:check-missed` | varre slots passados sem postagem e alerta Discord (rodado pelo scheduler a cada 10 min) |
| `posts:migrate-tiktok` | one-shot histórico: migrou tiktok_posts → social_posts |
| `tiktok:import-cookies-from-file` | one-shot histórico: importou cookies do filesystem → social_accounts.cookies |

## Idioma do código (PROIBIDO usar pt-BR)

- Nomes de **pastas, namespaces, classes, métodos, propriedades, variáveis,
  funções, migrations, colunas de tabela, env vars, config keys** — tudo
  em **inglês**. Ex.: `App\Livewire\Schedule\Index` (não `Agenda`);
  `auto_post_youtube_enabled` (não `postagem_youtube_habilitada`).
- Permitido em pt-BR: paths de rotas (`/agenda`, `/downloads`), strings
  de UI (labels, mensagens, toasts), comentários no código.

## Convenções

- `declare(strict_types=1)` em todo PHP, classes `final`.
- Pint impõe `mb_*` (`mb_trim`, `mb_rtrim`); `ext-mbstring` declarado no
  `composer.json`.
- PHPStan nível alto (`larastan/larastan` + `phpstan/phpstan` em modo bleeding-edge).
- Migrations consolidadas — em dev, prefira editar a migration de criação
  em vez de empilhar pequenas. Em prod, faça migration nova de drop/alter.

## Qualidade / CI

```bash
composer check      # phpstan + pint + rector (dry) + pest — é o que o CI roda
composer lint       # pint + rector aplicando fixes
```

- GitHub Actions: `lint.yml`, `tests.yml`, `automerge.yml` (squash automático
  de PRs verdes).

## Microserviço download-shorts (FastAPI, porta 8770)

Em `MicroServices/DownloadShorts/`. Magro: recebe `channel_url` +
`webhook_url`, lista os Shorts via `yt-dlp`, baixa em pool e dispara
**1 webhook por item terminado**.

- `POST /shorts/download` → `202 {status, count, channel_url}` (`409` se
  já há download ativo pro canal). `GET /health`.
- Webhook payload (1 item):
  `{ channel_url, items: [{ youtube_id, title, hashtags, status, storage_path?, storage_size_bytes?, storage_mime_type?, error? }] }`.
- Retry: 3 tentativas, backoff 1s/5s/15s.
- Subir: `docker compose up -d --build download-shorts`.
- Lado Laravel: `App\Services\Youtube\DownloadShortsClient::createDownload(channelUrl): int`.
  Webhook recebido em `/api/download-youtube/webhook` →
  `App\Services\Youtube\DownloadYoutubeImportService` insere em `youtube_shorts`.

## Microserviço tiktok-uploader (Node 22 + Playwright, porta 8090)

Em `MicroServices/TikTokUploader/`. Publica Shorts via navegador (Playwright
headless). **Não tem banco** e **não lê cookies do filesystem em prod** —
recebe os cookies no payload de cada `POST /posts`.

- `POST /posts` body: `{ video_id, title, hashtags, video_key?, webhook_url, cookies?: [...] }`.
- Webhook callback expandido: além dos campos antigos, agora envia
  `refreshed_cookies?` (cookies pós-upload, capturados do Playwright) e
  `session_status?` (`valid`/`invalid`/`unknown`). Laravel atualiza o
  `social_accounts.cookies` com isso.
- `DRY_RUN=true` no `.env` pula a publicação real (debugging).
- Logs cobrem o ciclo: cookies recebidos, upload status, cookies capturados,
  webhook tentativa/status (aceito/rejeitado), refresh count.
- Subir: `docker compose up -d --build tiktok-uploader`.

## Rodar tudo

```bash
docker compose up -d --build
```

Sobe `download-shorts` (8770) e `tiktok-uploader` (8090). MySQL e MinIO
ficam externos no host (`host.docker.internal:3306` / `:9000` dentro dos
containers, `127.0.0.1` no Laravel nativo).

No servidor de produção (Linux), o Laravel **roda nativo** via nginx +
PHP-FPM 8.4 (não usa Sail/container). Tem um `docker-compose.override.yml`
que desabilita o serviço `laravel` do compose principal pra evitar conflito
de porta 80.

## Histórico (apagados)

- `edsuuu/auto-post` — repositório anterior (CLI-only), absorvido no
  `generate-clips-laravel`, depois renomeado pra **MoneyClips**.
- Pipeline de cortes de vídeo longo — saiu do projeto (era um Python service
  separado `generate-clips`). Tudo o que era `App\Livewire\Videos\*`,
  `App\Models\Video/Cut/Transcript/...`, `App\Services\VideoProcessor\*`,
  `App\Services\StatusService` e as tabelas `videos/cuts/files/transcripts/
  video_payloads/statuses/status_logs/scheduled_posts/social_post_logs` foram
  removidos.
- Extensão Chrome `tiktok-cookie-bridge` e endpoint `/api/tiktok/cookies/ingest`
  — substituídos pelo fluxo via banco (textarea em `/settings/accounts`).
