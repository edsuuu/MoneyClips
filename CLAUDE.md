# MoneyClips

Plataforma de **postagem de Shorts** multi-plataforma (YouTube + TikTok).
Laravel orquestra; microserviços fazem o trabalho pesado (download, upload via
Playwright, corte/render de vídeo). **Tudo roda nativo — sem Docker**
(`make up`).

## Stack

- **PHP 8.4+ / Laravel 13+** (`bootstrap/app.php`)
- **Livewire 4 + Tailwind 4 + Vite** — kit próprio de componentes Blade em
  `resources/views/components/ui/` (sem Flux UI). Toasts: trait
  `App\Livewire\Concerns\WithToasts` → `components/ui/toasts.blade.php`.
- **MySQL** (`DB_CONNECTION=mysql`) + **fila em banco** (`QUEUE_CONNECTION=database`,
  filas nomeadas: `posting` e `processing` — worker no `make up` escuta
  `--queue=posting,processing,default --timeout=1800`)
- **MinIO** (S3-compatível) — disk `s3`, bucket `video`
- Qualidade: **PHPStan/Larastan**, **Pint**, **Rector** (CI roda `composer check`)
- Design de referência das telas: `docs/designs/*.dc.html` (claude.ai/design)

## Regra de arquitetura: só o Laravel toca o S3

Os microserviços de processamento/postagem **não têm credencial de storage**:
o Laravel baixa o vídeo do MinIO, envia o binário por HTTP (multipart) e grava
o resultado de volta. **Duas exceções**, ambas por inviabilidade de trafegar o
volume por HTTP:

1. o download do `media` (produtor de vídeo) sobe direto pro MinIO.
2. os endpoints por chave de storage do serviço `video` (`/package`, `/cut`,
   `/reframe`) — HLS vira **milhares** de segmentos e cortes/renders trafegam
   GBs; o serviço lê a fonte e escreve a saída direto no MinIO com credencial
   dedicada (a policy do usuário MinIO deve cobrir os prefixos `uploads/*`,
   `hls/*` e `videos/*`).

O **upload** também não passa pelo Laravel: o browser envia direto pro MinIO por
multipart presigned (o Laravel só assina as partes e confere o resultado), o que
contorna `upload_max_filesize`/`post_max_size` e dá retomada em arquivos de GBs.

## Domínio: estoque + postagem direta

```
media (FastAPI) → MinIO + youtube_shorts (estoque)
  → /meus-videos: revisão (título/hashtags) → ready_at
      → "Postar agora": 1 job PostShortToPlatformJob por plataforma escolhida
          → YoutubePosterService (Data API, síncrono no job)
          → TiktokPosterService (202 {job_id} → webhook fecha o desfecho)
```

## Organização de código (Services por integração)

- **Sufixo obrigatório no nome da classe**: `*Service`, `*Interface`, `*Data`
  (DTOs), `*Enum`, `*Job`, `*Cast`, `*Exception`, `*Controller`, `*Command`.
  SOLID simples — sem camadas de clean architecture.
- **A arquitetura é específica de cada serviço, não geral da aplicação**:
  interface/DTOs vivem NA PASTA do serviço dono (ex.:
  `PosterInterface`, `PostTaskData` e `PosterResultData` em
  `app/Services/AutoPost/`). NÃO existem pastas gerais tipo
  `app/Contracts` ou `app/DataTransferObjects`.
- **`*Enum` vive em `app/Enums/`** (`VideoStatusEnum`, `VideoCutStatusEnum`),
  não na pasta do serviço dono — mesma lógica das exceptions abaixo.
- **Exceptions são a exceção da regra acima**: `*Exception` vive em
  `app/Exceptions/`, não na pasta do serviço dono. É a convenção histórica do
  Laravel e o primeiro lugar onde se procura uma falha. Cada exception que
  fecha uma request HTTP implementa o próprio `render(): JsonResponse` — o
  código de status mora nela, não espalhado em `response()->json([...], 4xx)`
  pelos controllers.
- **`app/Services/API/`** — cada integração externa por API (não-microserviço)
  em sua pasta: `API/Youtube/` (Data API v3 + OAuth) e `API/Discord/` (webhook
  de alertas).
- **Clients de microserviço** na raiz de Services:
  `app/Services/{TikTokUploader,DownloadYoutube,Video}/` — `Video` concentra os
  clients do serviço `video` (:8790) e da transcrição (`CutRenderService`,
  `VideoCutEditRenderService`, `TranscribeService` — este último aponta pro
  `media`, :8770); `DownloadYoutube` aponta pro `Media` (:8770). O client de
  HLS (`HLSPackagerService`) vive em `app/Services/Upload/HLS/`.
- **Orquestração da postagem**: `app/Services/AutoPost/` (registry + DTOs dos
  posters).
- **Fuso horário**: `config/app.php` já define `America/Sao_Paulo` — NUNCA
  repita o timezone em código (`now()`/`CarbonImmutable::now()` já resolvem).
  `Date::use(CarbonImmutable::class)` é global (`AppServiceProvider`).

### Agenda (`schedule_slots` + `App\Services\AutoPost\`)

### Postagem (`App\Services\AutoPost\` + `social_posts`)

- `PosterRegistryService` (singleton no `AppServiceProvider`) — 1 Poster por
  plataforma implementando `App\Services\AutoPost\PosterInterface`
  (`post(PostTaskData): PosterResultData`; outcomes
  `ok|queued|dry-run|restricted|failed` — `queued` = desfecho chega por
  webhook, `externalId` gravado como uuid do ledger). Registrados:
  `YoutubePosterService` e `TiktokPosterService`.
- `PostShortToPlatformJob` (fila `posting`, `tries=1` — repost às cegas
  arrisca duplicado): cria a linha do ledger `social_posts`, valida o arquivo
  no MinIO e chama o poster da plataforma. Disparado pela "Postagem
  instantânea" da /meus-videos (1 job por plataforma escolhida; vídeo com
  social_post ativa não re-enfileira).

### Estoque (`youtube_shorts` + `/meus-videos`)

Ciclo: baixado (`video_path`) → revisado/pronto (`ready_at`) → postado
(`posted_youtube_at`/`posted_tiktok_at` + ledger `social_posts`). Os posters
SEMPRE usam `postableVideoPath()` (prefere `processed_video_path` legado,
quando existe). `template_rendered_at` alimenta a tab "Com template"
(histórico — o pipeline de template foi removido).

### YouTube

- `App\Services\Api\Youtube\ShortsPosterService` — upload resumível na
  YouTube Data API v3 (HTTP puro), chamado pelo `YoutubePosterService`.
  Credenciais em `social_accounts` (platform=`youtube`, OAuth Google, refresh
  via `YoutubeTokenRefresherService`). Connect em `/contas`. Download de
  canal (client do microserviço): `App\Services\DownloadYoutube\
  {DownloadShortsService, DownloadYoutubeImportService}`. Import de vídeo
  longo por URL (tela /upload): `App\Services\Upload\DownloadYoutubeService`
  → `StartYoutubeDownloadJob` → webhook `/api/webhook/download-video`
  (autenticado) fecha com claim `downloading → uploaded` e despacha o
  `StartHLSPackagingJob` — dali em diante é o fluxo normal de upload.

### TikTok (não-oficial, Playwright)

- **Integração ASSÍNCRONA**: `App\Services\TikTokUploader\
  TiktokUploaderService` faz `POST /posts` **multipart** (`video` binário +
  `cookies` JSON + `title` + `hashtags` + `webhook_url`) e recebe
  `202 {job_id}` na hora — o job_id vira o `uuid` do ledger. O Playwright
  publica em background e o desfecho chega em
  `POST /api/webhook/tiktok-posts` (`TiktokPostWebhookController`, autenticado
  pelo `X-Observability-Token` — o webhook escreve credenciais):
  `{job_id, status: completed|dry-run|restricted|failed, session_status,
  refreshed_cookies?, account_id?}` — fecha o ledger com claim atômico
  (`failed` prematuro do job é sobrescrevível pelo desfecho real), marca
  `posted_tiktok_at` e atualiza a conta identificada pelo `account_id`
  (`session_status=invalid` → Discord + `TiktokPosterService` curto-circuita
  as próximas postagens até renovar em /contas).
- Cookies vivem **criptografados no banco**: `social_accounts.cookies`
  (cast `encrypted:array`). O webhook devolve `refreshed_cookies` (capturados
  pós-upload) e o Laravel renova a sessão sozinho; fallback manual em
  `/contas` (emergência: `tiktok:import-cookies-from-file`).
- Status `restricted` (modal de moderação do TikTok) não volta pro estoque e
  gera warning (não error) no Discord; a sessão continua válida.
- O microserviço `MicroServices/TikTokUploader` é a fonte do contrato.

## Observabilidade

Push HTTP dos microserviços pro Laravel — sem Docker socket, sem Loki:

- `POST /api/observability/logs` (lote), autenticado por
  `X-Observability-Token` (`OBSERVABILITY_TOKEN`, fail-closed). Tabela
  `service_logs` (prune 14 dias).
- Tela `/observabilidade` (`App\Livewire\Observability\Index`): stream de
  logs (filtros por serviço/level + busca, poll 3s) + drawer de detalhe.
- Lado dos serviços: `RemoteObservability.ts` (Node) / `observability.py`
  (Python) — decoram o logger local (buffer, flush 2s/20 linhas,
  fire-and-forget). Ainda enviam heartbeat a cada 30s, mas o endpoint
  `/api/observability/heartbeat` foi removido (o POST volta 404 inofensivo).
  Envs: `OBSERVABILITY_URL`, `OBSERVABILITY_TOKEN`, `SERVICE_NAME`.

## Banco de dados (visão geral)

| Tabela | Papel |
| --- | --- |
| `users` | login Google OAuth |
| `social_accounts` | credenciais por plataforma (OAuth do YT, cookies do TT) |
| `videos` | vídeos longos enviados em /upload (arquivo ou URL do YouTube): ciclo `awaiting_upload\|downloading → uploaded → packaging → ready` + metadados do HLS |
| `youtube_shorts` | estoque; ciclo `ready_at` → `posted_*_at` |
| `social_posts` | ledger de postagens (1 linha por disparo/plataforma) |
| `service_logs` | observabilidade (logs dos microserviços) |

## Telas (layout navbar; design em docs/designs/)

| Rota | Componente | Função |
| --- | --- | --- |
| `/meus-videos` | `App\Livewire\Videos\Index` | estoque com tabs Disponíveis (Baixados/Prontos), Com template, Postados; postagem instantânea; novo download |
| `/upload` | `App\Livewire\Uploads\Create` | envio de vídeo longo (multipart direto pro MinIO, com retomada) OU import por URL do YouTube (valida + preview → download no microserviço) |
| `/meus-uploads` | `App\Livewire\Uploads\{Index,Show}` | biblioteca dos vídeos longos + player HLS adaptativo |
| `/editor-de-video/{cut}` | `App\Livewire\VideoEditor\Index` | reframe do corte por keyframes (crop 9:16, modos, legendas) + "Gerar corte editado" → render no serviço `video` → estoque de `/meus-videos` |
| `/contas` | `App\Livewire\Accounts\Index` | cards de contas (TikTok email/senha + status de sessão; YouTube OAuth) com toggle por conta |
| `/observabilidade` | `App\Livewire\Observability\Index` | stream de logs dos microserviços |

Não existe redirect legado: cada tela tem UMA rota. Link novo aponta pra rota
final — nada de `Route::redirect` pra não mexer na navbar.

## Comandos artisan

| Comando | O que faz |
| --- | --- |
| `uploads:prune-stale` | aborta uploads multipart abandonados > 24h (diário) |
| `tiktok:import-cookies-from-file` | fallback de emergência: importa cookies do filesystem |
| `posts:migrate-tiktok` | one-shot histórico (tiktok_posts → social_posts) |

Cron: `* * * * * php artisan schedule:run` + worker de fila
(`queue:listen --queue=posting,processing,default --tries=1 --timeout=1800`).

## Idioma do código (PROIBIDO usar pt-BR)

- Nomes de **pastas, namespaces, classes, métodos, propriedades, variáveis,
  funções, migrations, colunas de tabela, env vars, config keys** — tudo
  em **inglês**. Ex.: `App\Livewire\Accounts\Index` (não `Contas`).
- Permitido em pt-BR: paths de rotas (`/agenda`, `/meus-videos`), strings
  de UI (labels, mensagens, toasts), comentários no código.

## Convenções

- `declare(strict_types=1)` em todo PHP, classes `final`.
- **PROIBIDO comentário em cima de variável/propriedade/método** — vale pra
  PHP e Blade. O nome já explica; se precisa de comentário, o nome está
  errado. Exceção: algo **muito específico** que o nome não carrega (regra de
  negócio não óbvia, pegadinha de concorrência, `ponytail:` com o teto da
  simplificação). Docblock só quando tem anotação que o PHPStan usa
  (`@var`, `@return`, `@param`, `@property`) — nunca só pra repetir o nome.
- Pint impõe `mb_*` (`mb_trim`, `mb_rtrim`); `ext-mbstring` no `composer.json`.
  O `ordered_class_elements` do pint.json NÃO ordena métodos de propósito —
  a ordem é manual (regra abaixo).
- PHPStan nível max (`larastan` + bleeding-edge).
- **PROIBIDO escrever arquivo de migration na mão.** Toda migration nova sai
  de `php artisan make:migration <nome_snake_case>` — o timestamp do nome
  define a ordem de execução e inventá-lo à mão quebra a sequência. Depois
  edite o esqueleto gerado (e acrescente `declare(strict_types=1);`, que o
  stub do Laravel não traz).
- Editar a migration de criação só vale enquanto ela ainda não rodou em
  nenhum banco. Se a coluna já existe em dev/prod, é `make:migration` de
  alter/drop — senão a mudança nunca chega ao banco sem `migrate:fresh`.

### Livewire / Blade (front)

- Tela = `Route::view()` → blade wrapper (`resources/views/<area>/index.blade.php`
  com `<x-layout layout="navbar">` + `<livewire:...>`) → componente
  `App\Livewire\<Area>\Index`.
- **PROIBIDO `@php` em blade.** Lógica/formatos/labels/datas vêm prontos do
  `render()` (view-models). Classes condicionais SEMPRE via `@class([...])` —
  nunca ternário dentro de `class=""`. Mapas de cor por status viram strings
  de classe no componente, aplicadas com `@class([$x => true])`.
- **PROIBIDO comentário em blade** (`{{-- --}}`) e comentário em cima de
  variável. O nome (do componente, prop, seção) já explica; se precisa de
  comentário, o nome está errado.
- Ordem de métodos no componente: `mount()` primeiro → ações públicas →
  helpers privados → **`render()` por último**.
- Propriedade pública = fronteira de confiança: valide/saneie nas ações.
- Reuse antes de escrever: `App\Support\Hashtags` (hashtag ⇄ input),
  `App\Jobs\Concerns\TransfersStorageFiles` (MinIO ⇄ tmp), componentes
  `x-ui.toggle`, `x-ui.server-modal` (modal @if server-driven),
  `x-ui.modal` (Alpine), `x-log-level-badge`, e
  `components/navbar.blade.php` (fonte ÚNICA de navegação —
  desktop + drawer mobile).

## Qualidade / CI

```bash
composer check      # phpstan + lint + pest — é o que o CI roda (tests.yml)
composer lint       # pint + rector — ambos APLICAM fixes (commite o resultado)
```

## Git / commits

- **SEMPRE trabalhar em branch** — nunca commitar direto na `main`. Padrão de
  nome: `feat/`, `fix/`, `refactor/`, `chore/`, `docs/` + descrição curta em
  kebab-case (ex.: `feat/upload-de-video`). Fluxo: branch → commits →
  `gh pr create` → o automerge cuida do merge → voltar pra `main` e
  `git pull`.
- **PROIBIDO co-autor em commit.** Nada de `Co-Authored-By:` (nem Claude, nem
  qualquer assistente) e nada de "Generated with" no corpo. A mensagem do
  commit é só o texto da mensagem.
- Nunca commitar credencial: cookies do TikTok
  (`MicroServices/TikTokUploader/cookies/`), `.env`, tokens. O `.gitignore`
  cobre — se algo aparecer como untracked ali, é bug do ignore, não commite.

## Microserviços (MicroServices/ — todos nativos, sem docker)

| Serviço | Porta | Stack | Contrato |
| --- | --- | --- | --- |
| media | 8770 | FastAPI + yt-dlp + faster-whisper | **único serviço Python** (download + transcrição, filas separadas). `POST /shorts/download {channel_url, webhook_url}` → 202; 1 webhook/item. `GET /videos/metadata?url=` → dados do vídeo (400 URL inválida/live, 404 indisponível). `POST /videos/download {url, video_uuid, video_key, webhook_url}` → 202; fila de 1 consumidor baixa em ≤1080p (fallback progressivo de formato), sobe na key EXATA e ecoa `{video_uuid, status: completed\|failed, size_bytes, ...}` com `X-Observability-Token`. Sobe direto pro MinIO (exceção da regra S3). `POST /transcriptions` multipart {audio, uuid, webhook_url} → 202 {job_id}; fila própria + `gpu_lock` (faster-whisper, CUDA em prod, cpu/int8 no macOS); webhook `{uuid, status: done\|failed, transcript: {segments: [{start, end, text, words: [{word, start, end, score}]}], language}}`. Chamado pelo `video` (template) E pelo Laravel (vídeo longo e cortes) |
| tiktok-uploader | 8090 | Node 22 + Playwright | `POST /posts` multipart {video, cookies, title, hashtags, webhook_url} → **202 {job_id}**; fila serial em memória; webhook `{job_id, status, session_status, refreshed_cookies?}`; `POST /session`, `POST /login`, `GET /health` |
| video | 8790 | Node 22 + ffmpeg + sharp | **todo o ffmpeg da aplicação**: cinco endpoints, filas independentes. `POST /reencode` multipart {video, video_id?} → binário `_HQ` (X-Reencode: completed) ou JSON `skipped` (síncrono, sem S3). `POST /package` JSON {video_key, output_prefix, webhook_url} → 202 {uuid}; HLS/ABR (360p/720p/1080p, fMP4, segmentos de 6s); lê/escreve MinIO direto (exceção da regra S3); webhook `{uuid, status: done\|failed\|rejected\|progress, ...}`. `POST /cut` JSON {cut_uuid, video_key, start_seconds, end_seconds, clip_key, audio_key, webhook_url} → 202 {uuid}; corte frame-exato (cap 1080p) + WAV pra transcrição; webhook `{uuid, cut_uuid, status: done\|failed, audio}`. `POST /reframe` JSON {edit_uuid, source_key, output_key, source, keyframes, settings, transcript?, webhook_url} → 202 {uuid}; render do corte editado em 1080x1920 (zoompan por keyframes + legenda opcional); webhook `{uuid, edit_uuid, status: done\|failed}`. `POST /videos` multipart {file, variants, caption_position, channel_name, channel_handle, webhook_url} → 202 {uuid}; render de legenda karaokê + template; webhook `{uuid, status: done\|failed, files}`; output em `GET /videos/{uuid}/output/{variant}`. `API_TOKEN` opcional |

Todos com observabilidade (logs → Laravel) quando
`OBSERVABILITY_URL`/`OBSERVABILITY_TOKEN` configurados.

## Rodar tudo

```bash
make setup   # 1ª vez: deps + .env de tudo (Laravel + 3 serviços)
make up      # sobe Laravel (serve/queue/pail/vite) + media +
             # tiktok-uploader + video — sem docker
```

- Laravel → microserviço: `127.0.0.1:<porta>`; microserviço → Laravel:
  `127.0.0.1:8000` em dev, domínio real (nginx/HTTPS) em prod.
- A transcrição (faster-whisper `large-v3`, no `media`) usa CUDA em prod; em
  macOS cai pra cpu/int8 automaticamente.
- Prod: pm2/systemd por serviço (só o TikTokUploader tem
  `ecosystem.config.cjs` por enquanto).

## Runbook de deploy desta refatoração

1. `php artisan migrate` (dropa `schedule_slots`, `processing_jobs`,
   `service_heartbeats` e colunas órfãs)
2. Setar `OBSERVABILITY_TOKEN` no Laravel + nos `.env` dos 3 serviços (o
   webhook do TikTok também autentica por ele — sem token, post não fecha)
3. Conferir `TIKTOK_POST_WEBHOOK_URL` (em prod: domínio real, não `:8000`) e
   `TIKTOK_POST_API_TOKEN` = `API_TOKEN` do uploader
4. ⚠️ Rotacionar a chave Roboflow e o webhook Discord que estavam commitados
   no `.env.example` antigo do TikTokUploader (continuam no histórico git)

## Armadilhas conhecidas (custaram tempo, não são óbvias)

- **`.gitignore` casa em qualquer profundidade.** `storage/` já engoliu um
  módulo de código (`AutoCaption/app/storage/local.py`): funcionava na máquina
  de quem escreveu e o serviço não subia em nenhuma outra. Ao criar pasta com
  nome genérico dentro de um serviço, confira com `git check-ignore -v`.
- **Rename só de case exige `git mv`.** macOS é case-insensitive; o git guarda
  o case antigo e o PSR-4 quebra no Linux do CI.
- **`phpunit.xml` vence o `.env.testing`.** O PHPUnit seta as vars antes do
  bootstrap e o `safeLoad()` do Dotenv não sobrescreve. Consequência: a suíte
  roda em **sqlite `:memory:`** enquanto prod é MySQL — migration com tipo de
  coluna específico ou cast de JSON pode passar no CI e quebrar em prod.
- **`php artisan key:generate` precisa da linha `APP_KEY=`.** Em `.env` vazio
  ele não acha o que substituir, sai sem escrever e **sem erro**.
- **`DateOnlyCast` existe por causa do sqlite dos testes**: o `immutable_date`
  nativo grava `Y-m-d H:i:s` e quebra comparação por data (MySQL trunca,
  sqlite não).
- **`php artisan view:clear` faz parte do deploy**: trocar componente anônimo
  por componente de classe com o mesmo nome quebra com o cache antigo.
- **Automerge está ATIVO** (`gh workflow disable automerge.yml` desliga). Ele
  mergeia sozinho (squash) segundos após TODOS os CIs ficarem verdes —
  `tests`, `tiktok-uploader`, `media` e `video` — então qualquer
  push vira merge sem revisão. Duas exceções que exigem merge manual: PR que
  altera `.github/workflows/` (o `GITHUB_TOKEN` não tem escopo `workflows`) e
  o próprio PR que reativa/edita o automerge (a versão que roda é a da `main`).

## Pendências

- ⚠️ **Rotacionar a chave Roboflow e o webhook Discord** que estavam
  commitados no `.env.example` antigo do TikTokUploader — seguem no histórico
  do git.
- Renomear as chaves `HLS_*`: apontam pro serviço `Video`, não mais pro
  serviço que dá nome a elas.
- Os microserviços ainda enviam heartbeat (30s) pra um endpoint que não existe
  mais — remover o heartbeat de `RemoteObservability.ts`/`observability.py`
  quando conveniente.

## Agentes e contexto

- Agente especializado no projeto: `.claude/agents/moneyclips-expert.md`
  (arquitetura, convenções e workflow de verificação — use para qualquer
  feature/refactor/review neste repo).
- Histórico das refatorações vive no git (PRs #48, #61, #62, #63) — este
  arquivo descreve o estado ATUAL.

## Histórico (apagados nas refatorações)

- **Agenda de auto-postagem** (`schedule_slots` + tela `/agenda` +
  `AutoPostDispatcherService`/`SlotStatusService`/`WeekGeneratorService`/
  `StockAlertService` + modo aleatório + `schedule:migrate-legacy`/
  `auto-post:check-missed`) — ficou só a postagem direta
  (`PostShortToPlatformJob`, ex-`PostSlotToPlatformJob`).
- **Pipeline reencode/template** (`processing_jobs`,
  `App\Services\{Processing,Reencode,AutoCaption}`, `TemplateEditor`,
  `TemplateStyleEnum`, rota `/api/webhook/autocaption`).
- **Heartbeats** (`service_heartbeats`, endpoint `/api/observability/heartbeat`,
  `observability:check-heartbeats`, cards da /observabilidade).
- **Stubs de posters** (`TiktokOfficialPosterService`,
  `InstagramReelsPosterService`, `FacebookReelsPosterService`,
  `KwaiPosterService`) e a tabela `platform_settings`.
- Clients `App\Services\{Cut,Transcribe,VideoCutEdit}` → centralizados em
  `App\Services\Video`.
- Integração TikTok assíncrona antiga: `TiktokPostService`,
  `TIkTokUploaderClient`, `TikTokPostDispatcher`,
  `TiktokPostCallbackController` (+ rota `/api/tiktok-posts/callback`).
- `WindowSchedule` (horários fixos + minuto crc32) e `StockReservation`
  (sorteio) — substituídos por `schedule_slots` + dispatcher por slot.
- `MicroserviceMonitor` (logs via Docker socket) e a tela `/microservices`.
- Telas `App\Livewire\Downloads\*` (viraram `/meus-videos`).
- Colunas `users.auto_post_{youtube,tiktok}_enabled` → `platform_settings`.
- Reencode por chave S3 + fila em memória + webhook → multipart síncrono.
- `App\Livewire\Settings\Accounts` + rota `/social-accounts` (duplicata de
  `/contas`) e os redirects `/downloads` e `/microservices`.
- Serviços `DownloadYoutube` (:8770) e `Transcriber` (:8780) — fundidos no
  `Media` (:8770, filas separadas). Ao deployar: (1) apagar as linhas
  `download-youtube` e `transcriber` de `service_heartbeats` (upsert por nome
  — linha órfã alerta "fora do ar" pra sempre); (2) nada mais escuta o `:8780`
  — se algum `.env` de prod fixa `TRANSCRIBE_URL` (Laravel) ou
  `TRANSCRIBER_URL` (Video) apontando pra `:8780`, trocar pra `:8770` e rodar
  `php artisan config:cache` (o default no código já é `:8770`, mas env
  explícito vence).
- `MicroServices/GenerateClips` — pipeline monolítico antigo (vídeo longo →
  cortes). O que ainda não foi portado está em `GENERATE_CLIPS_PENDENTE.md`;
  o código vive no histórico do git.
