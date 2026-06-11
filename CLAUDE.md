# generate-clips-laravel

Plataforma unificada de geração de clipes e auto-postagem em redes sociais.
Este repositório absorveu o projeto **auto-post** (antes um app Laravel
separado, CLI-only, em `edsuuu/auto-post`) — toda a funcionalidade dele vive
aqui agora.

## Stack

- PHP 8.4+ / Laravel 12+ (estrutura `bootstrap/app.php`)
- Livewire 3 + Tailwind 4 + Vite (frontend) — **sem Flux UI**: kit próprio de
  componentes Blade em `resources/views/components/ui/` (button, input, badge,
  modal, dropdown, icon, toasts...) com Alpine (embutido no Livewire).
  Toasts: trait `App\Livewire\Concerns\WithToasts` (`$this->toast(msg, variant)`)
  → evento `toast` consumido por `components/ui/toasts.blade.php`.
- Pest (testes), PHPStan/Larastan, Pint, Rector (qualidade)
- MySQL (`DB_CONNECTION=mysql`), fila em banco (`QUEUE_CONNECTION=database`)
- MinIO (S3-compatível) para vídeos — disk `minio`
- Serviço externo Python (FastAPI) para processamento pesado de vídeo

## Os 3 domínios da aplicação

### 1. Pipeline de vídeo/cortes (Laravel orquestra, Python processa)

Fluxo: usuário cola a URL do vídeo em `/videos/create` → `ProcessVideoJob`
chama a **API Python de processamento** (download/transcrição/render; padrão
`http://127.0.0.1:8765`, config `config/video-processor.php`) → o Python salva
no MinIO e responde via webhook (`POST /api/video-processor/callbacks`,
`VideoProcessorCallbackController` → `VideoProcessorCallbackService`).

- `app/Services/VideoProcessor/` — `VideoProcessorService` (regra de negócio,
  DTOs em `Data/`), `HttpVideoProcessorProvider` (HTTP), callback service.
- `app/Livewire/Videos/` — `Create`, `Index`, `Editor` (timeline de cortes,
  recomendação por IA via `generateAiCuts`, render, publicação rápida),
  `Schedule` (agendamento social por corte).
- Progresso em tempo real: o browser consome o WebSocket do Python
  (`video-processor.ws_url`); o job em andamento fica em `videos.current_job_id`.
- Tabelas: `videos`, `cuts`, `files`, `transcripts`, `video_payloads`,
  `statuses`/`status_logs` (transições via `StatusService`).

### 2. Publicação social (cortes → redes)

- `app/Services/SocialPublishing/` — `SocialPublisherRegistry` + publishers
  reais (`YouTubePublisher`, `TikTokPublisher`, `InstagramPublisher`,
  `FacebookPublisher`), `PostDraftBuilder`, OAuth (`SocialAccountConnector`,
  `TokenRefresher`).
- Contas conectadas em **`social_accounts`** (tokens criptografados) via
  OAuth 1-click em `/social-accounts` (`OAuthController`, rotas
  `/oauth/{platform}/connect|callback`) ou token manual.
- Posts persistem em `scheduled_posts` (+ `social_post_logs`).
  `social:publish-due` (scheduler, a cada minuto) marca vencidos e despacha
  `PublishScheduledPostJob`. Dashboard em `/posts`.
- Login do usuário é **Google OAuth apenas** (sem registro por formulário):
  `/oauth2/google/redirect|callback` no `OAuthController`.

### 3. Auto-postagem de Shorts (unificado do projeto auto-post)

Pipeline de "estoque": baixa Shorts de canais → guarda no MinIO → sorteia e
posta automaticamente no canal conectado.

- **Download (CLI)**: `php artisan youtube:download-shorts "<url-do-canal>"`
  (`DownloadChannelShorts` + `app/Services/Youtube/YoutubeChannelService`,
  usa `yt-dlp` via `Process`). Salva em `youtube_shorts` + MinIO
  (`shorts/{id}.mp4`).
- **Sorteio (CLI/scheduler)**: `php artisan youtube:dispatch-posts [--count=N]`
  (`DispatchYoutubePosts`) sorteia Shorts baixados não postados e enfileira um
  `PostYoutubeShortJob` por sorteado. Avisa estoque baixo no Discord
  (throttle 1x/dia).
- **Postagem**: `PostYoutubeShortJob` → `app/Services/Youtube/ShortsPoster`
  (upload resumível na YouTube Data API v3, HTTP puro, sem google/apiclient).
  Marca `posted_at` + `youtube_video_id` no Short.
- **Credenciais**: usa a mesma `SocialAccount` (platform=`youtube`) dos
  publishers — conecta pela web (`/social-accounts`) ou pelo CLI
  `php artisan youtube:link` (cola a URL de redirect, persiste em
  `social_accounts`). Refresh automático via `TokenRefresher`.
- **Notificações**: `app/Services/DiscordNotifier` (webhook em
  `config/youtube_shorts.php` → `discord_webhook`).
- **Frontend**: página `/shorts` (`App\Livewire\Shorts\Index`) com métricas de
  estoque, filtros, "postar agora" e sorteio manual.
- **Scheduler**: `routes/console.php` roda `youtube:dispatch-posts` nos
  horários de engajamento (09/12/15/18/20/22h, América/São_Paulo).
- Tabela: `youtube_shorts` (`youtube_id`, `channel_url`, `title`, `hashtags`,
  `video_path`, `youtube_video_id`, `downloaded_at`, `posted_at`).
  O ciclo de vida vive no próprio registro — não existe tabela de jobs própria.

## Banco de dados (visão geral)

| Tabela | Papel |
| --- | --- |
| `videos`, `cuts`, `files`, `transcripts`, `video_payloads` | pipeline de clipes |
| `statuses`, `status_logs` | máquina de estados + auditoria (polimórfico) |
| `social_accounts` | contas OAuth conectadas (YouTube/TikTok/IG/FB) — **fonte única de credenciais** |
| `scheduled_posts`, `social_post_logs` | agendamento/publicação de cortes |
| `youtube_shorts` | estoque de Shorts do pipeline de auto-postagem |
| `users` (+ colunas google_*) | login via Google OAuth |

Migrations consolidadas — ao mexer em schema deste projeto em dev, o padrão da
casa é editar a migration de criação em vez de acumular migrations pequenas.

## Comandos artisan do domínio

| Comando | O que faz |
| --- | --- |
| `youtube:download-shorts <canal> [--limit=N]` | baixa Shorts do canal p/ MinIO + banco |
| `youtube:dispatch-posts [--count=N]` | sorteia e enfileira postagens de Shorts |
| `youtube:link [url-ou-code]` | vincula canal do YouTube por OAuth no terminal |
| `social:publish-due` | publica `scheduled_posts` vencidos (scheduler) |

## Rodando localmente

Serviços necessários: MySQL, MinIO, o serviço Python de processamento
(`python main.py`, porta 8765), worker de fila e scheduler.

```bash
composer setup      # install + env + key + pnpm + build
composer dev        # serve + queue:listen + pail + vite em paralelo
php artisan schedule:work   # agendamentos (publicações + shorts)
```

Para postar de verdade é preciso conectar contas com OAuth (credenciais
`GOOGLE_AUTH_*`/`META_*`/`TIKTOK_*` no `.env`) — ver seções do `.env.example`.

## Qualidade / CI

```bash
composer check      # phpstan + pint + rector (dry) + pest — é o que o CI roda
composer lint       # pint + rector aplicando fixes
php artisan test    # suíte Pest
```

- GitHub Actions: `lint.yml` (composer lint), `tests.yml` (composer check),
  `automerge.yml` (squash automático de PRs verdes).
- PHPStan nível alto: use `App\Support\Cast` (`Cast::str/int/float/arr`) para
  converter `mixed` com segurança.
- Pint impõe `mb_*` (ex.: `mb_trim`, `mb_rtrim`) — `ext-mbstring` declarado no
  composer.json.
- Convenções: `declare(strict_types=1)`, classes `final`, comentários e UI em
  pt-BR.

## Repositórios relacionados

- `edsuuu/auto-post` — **absorvido por este repo** (mantido só como histórico).
- Serviço Python de processamento de vídeo — projeto separado local
  (FastAPI + MinIO + webhook), não versionado aqui.
