# MoneyClips

Plataforma de **auto-postagem de Shorts** multi-plataforma (YouTube + TikTok
hoje; TikTok oficial/Instagram/Facebook/Kwai com posters preparados). Laravel
orquestra; microserviços fazem o trabalho pesado (download, upload via
Playwright, reencode, render de template). **Tudo roda nativo — sem Docker**
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

1. `download-shorts` (produtor de vídeo) sobe direto pro MinIO.
2. `hls` — um vídeo longo vira **milhares** de segmentos; o serviço lê a fonte
   em `uploads/*` e escreve a saída em `hls/*` com credencial dedicada (a policy
   do usuário MinIO deve limitar exatamente a esses dois prefixos).

O **upload** também não passa pelo Laravel: o browser envia direto pro MinIO por
multipart presigned (o Laravel só assina as partes e confere o resultado), o que
contorna `upload_max_filesize`/`post_max_size` e dá retomada em arquivos de GBs.

## Domínio: agenda em banco + estoque

```
download-shorts (FastAPI) → MinIO + youtube_shorts (estoque)
  → /meus-videos: revisão (título/hashtags) → ready_at
      → opcional: reencode (síncrono) OU template (assíncrono) — ambos no Video
  → /agenda: schedule_slots (data+hora+vídeo) → AutoPostDispatcherService (cron)
      → claim atômico do slot → 1 job PostSlotToPlatformJob por plataforma habilitada
          → YoutubePosterService (Data API, síncrono no job)
          → TiktokPosterService (202 {job_id} → webhook fecha o desfecho)
          → stubs (TikTok oficial, Instagram, Facebook, Kwai)
```

## Organização de código (Services por integração)

- **Sufixo obrigatório no nome da classe**: `*Service`, `*Interface`, `*Data`
  (DTOs), `*Enum`, `*Job`, `*Cast`, `*Exception`, `*Controller`, `*Command`.
  SOLID simples — sem camadas de clean architecture.
- **A arquitetura é específica de cada serviço, não geral da aplicação**:
  interface/DTOs/enums vivem NA PASTA do serviço dono (ex.:
  `PosterInterface`, `PostTaskData` e `PosterResultData` em
  `app/Services/AutoPost/`; `TemplateStyleEnum` e `TemplateRenderOptionsData`
  em `app/Services/Processing/`). NÃO existem pastas gerais tipo
  `app/Contracts` ou `app/DataTransferObjects`.
- **`app/Services/Api/`** — cada integração externa por API (não-microserviço)
  em sua pasta: `Api/Youtube/` (Data API v3 + OAuth), `Api/TikTok/` (Content
  Posting API oficial, stub), `Api/Meta/{Instagram,Facebook}/`, `Api/Kwai/`,
  `Api/Discord/` (webhook).
- **Clients de microserviço** espelham `MicroServices/` na raiz de Services:
  `app/Services/{TikTokUploader,DownloadShorts,Reencode,AutoCaption,HLS}/` —
  `Reencode`, `AutoCaption` e `HLS` apontam todos pro serviço `Video` (:8790),
  em endpoints diferentes. (Os nomes `Reencode`/`AutoCaption` são herdados dos
  serviços que existiam antes da fusão; o alvo hoje é sempre o `Video`.)
- **Orquestração**: `app/Services/AutoPost/` (agenda/postagem) e
  `app/Services/Processing/` (pipeline reencode/template).
- **Fuso horário**: `config/app.php` já define `America/Sao_Paulo` — NUNCA
  repita o timezone em código (`now()`/`CarbonImmutable::now()` já resolvem).
  `Date::use(CarbonImmutable::class)` é global (`AppServiceProvider`).
- **Horários de postagem vêm SEMPRE do banco** (semana anterior →
  `users.auto_post_schedule`) — não existe horário default em código.

### Agenda (`schedule_slots` + `App\Services\AutoPost\`)

- `ScheduleSlot` — slot concreto: `slot_date` + `slot_time` (fuso da
  aplicação), `youtube_short_id` (null = vazio), `is_active`, `dispatched_at`
  (claim atômico). Máx. 5/dia. `scheduledAt()` é o único ponto que combina
  data+hora.
- `AutoPostDispatcherService` — a cada minuto busca slots devidos (tolerância
  `GRACE_MINUTES = 5`), reivindica via `UPDATE ... WHERE dispatched_at IS NULL`
  e enfileira `PostSlotToPlatformJob` (fila `posting`, `tries=1` — repost às
  cegas arrisca duplicado). O tick nunca posta nada.
- `PosterRegistryService` (singleton no `AppServiceProvider`) — 1 Poster por
  plataforma implementando `App\Services\AutoPost\PosterInterface`
  (`post(PostTaskData): PosterResultData`; outcomes
  `ok|queued|dry-run|restricted|failed` — `queued` = desfecho chega por
  webhook, `externalId` gravado como uuid do ledger). Toggles em
  **`platform_settings`** (tela /agenda). Stubs prontos:
  `TiktokOfficialPosterService`, `InstagramReelsPosterService`,
  `FacebookReelsPosterService`, `KwaiPosterService` (docblocks apontam a API
  alvo; credenciais irão em `social_accounts`).
- `SlotStatusService` — status de exibição computado na leitura (nunca
  persistido): `empty|paused|future|next|due|skipped` e, pós-despacho,
  agregado das `social_posts` do slot: `posting|posted|partial|failed`.
- `WeekGeneratorService` — "Gerar semana": copia horários da última semana com
  slots (fallback: agenda legada `users.auto_post_schedule`) e auto-atribui
  vídeos prontos (FIFO `ready_at`). Sem nada no banco, não cria slot — o
  operador monta a primeira semana na /agenda.
- `StockAlertService` — 1×/dia compara estoque pronto × slots vazios de 7 dias.
- **Modo aleatório** (flag `random_mode` no cache — `AutoPostDispatcherService::RANDOM_MODE`, toggle na /agenda): com a
  flag ligada, slot VAZIO que chega no horário recebe um vídeo pronto
  sorteado (fora do sorteio: vídeo com social_post ativa ou preso em slot
  despachado sem ledger) e roda o fluxo antigo à parte —
  `ReencodeAndPostSlotJob` (pega o vídeo → reencoda via
  `ReencodeShortService` → `fanOut()` normal). Atribuição + claim na MESMA
  UPDATE (senão o tick seguinte postaria o original em paralelo ao reencode).
  Reencode falhou = posta o original. Flag desligada = slot vazio fica
  `skipped` (comportamento padrão).
- Deploy da agenda: rodar `php artisan schedule:migrate-legacy` UMA vez após
  `migrate` (materializa slots da agenda legada; sem isso nada posta).

### Estoque (`youtube_shorts` + `/meus-videos`)

Ciclo: baixado (`video_path`) → revisado/pronto (`ready_at`) → opcionalmente
processado (`processed_video_path` — os posters SEMPRE usam
`postableVideoPath()`) → agendado (slot) → postado (`posted_youtube_at`/
`posted_tiktok_at` + ledger `social_posts`). `template_rendered_at` alimenta a
tab "Com template".

### Pipeline de processamento (`App\Services\Processing\` + `processing_jobs`)

Fluxo 1 do estoque: o operador escolhe **só reencode** OU **template**.

- `VideoProcessingService::startReencode()` → `RunReencodeJob` (fila
  `processing`): MinIO → multipart `POST /reencode` via
  `App\Services\Reencode\ReencodeService` (síncrono, resposta = binário `_HQ`
  ou JSON `skipped`) → MinIO → `processed_video_path`.
- `VideoProcessingService::startTemplateRender()` → `StartTemplateRenderJob`:
  MinIO → multipart `POST /videos` no serviço `Video`
  (`App\Services\AutoCaption\AutoCaptionService`, com `webhook_url`) →
  webhook `POST /api/autocaption/webhook` → `FetchTemplateOutputJob` baixa o
  variant e grava no MinIO. Estilos: `TemplateStyleEnum` (Claro/Escuro/Vertical
  → variants `template_white|template_black|vertical`). O `Video` monta o .ass
  e roda o ffmpeg; a transcrição ele terceiriza pro `transcriber` (:8780).
- 1 job pendente por vídeo (guard em `processing_jobs`).

### YouTube

- `App\Services\Api\Youtube\ShortsPosterService` — upload resumível na
  YouTube Data API v3 (HTTP puro), chamado pelo `YoutubePosterService`.
  Credenciais em `social_accounts` (platform=`youtube`, OAuth Google, refresh
  via `YoutubeTokenRefresherService`). Connect em `/contas`. Download de
  canal (client do microserviço): `App\Services\DownloadShorts\
  {DownloadShortsService, DownloadYoutubeImportService}`.

### TikTok (não-oficial, Playwright)

- **Integração ASSÍNCRONA**: `App\Services\TikTokUploader\
  TiktokUploaderService` faz `POST /posts` **multipart** (`video` binário +
  `cookies` JSON + `title` + `hashtags` + `webhook_url`) e recebe
  `202 {job_id}` na hora — o job_id vira o `uuid` do ledger. O Playwright
  publica em background e o desfecho chega em
  `POST /api/tiktok-posts/webhook` (`TiktokPostWebhookController`, autenticado
  pelo `X-Observability-Token` — o webhook escreve credenciais):
  `{job_id, status: completed|dry-run|restricted|failed, session_status,
  refreshed_cookies?, account_id?}` — fecha o ledger com claim atômico
  (`failed` prematuro do job é sobrescrevível pelo desfecho real), marca
  `posted_tiktok_at` e atualiza a conta identificada pelo `account_id`
  (`session_status=invalid` → Discord + `TiktokPosterService` curto-circuita
  os próximos slots até renovar em /contas).
- Cookies vivem **criptografados no banco**: `social_accounts.cookies`
  (cast `encrypted:array`). O webhook devolve `refreshed_cookies` (capturados
  pós-upload) e o Laravel renova a sessão sozinho; fallback manual em
  `/contas` (emergência: `tiktok:import-cookies-from-file`).
- Status `restricted` (modal de moderação do TikTok) não volta pro estoque e
  gera warning (não error) no Discord; a sessão continua válida.
- O microserviço `MicroServices/TikTokUploader` é a fonte do contrato.

## Observabilidade (OBSERVABILITY.md)

Push HTTP dos microserviços pro Laravel — sem Docker socket, sem Loki:

- `POST /api/observability/logs` (lote) e `POST /api/observability/heartbeat`
  (30s), autenticados por `X-Observability-Token` (`OBSERVABILITY_TOKEN`,
  fail-closed). Tabelas `service_logs` (prune 14 dias) e `service_heartbeats`
  (upsert por serviço).
- `observability:check-heartbeats` (a cada minuto): sem heartbeat > 90s →
  Discord 1×/queda + aviso de recuperação.
- Tela `/observabilidade` (`App\Livewire\Observability\Index`): stream de
  logs (filtros por serviço/level + busca, poll 3s) + cards de heartbeat +
  drawer de detalhe. Substituiu `/microservices` e o `MicroserviceMonitor`.
- Lado dos serviços: `RemoteObservability.ts` (Node) / `observability.py`
  (Python) — decoram o logger local (buffer, flush 2s/20 linhas,
  fire-and-forget) + heartbeat. Envs: `OBSERVABILITY_URL`,
  `OBSERVABILITY_TOKEN`, `SERVICE_NAME`.

## Banco de dados (visão geral)

| Tabela | Papel |
| --- | --- |
| `users` | login Google OAuth (`auto_post_schedule` legado — fonte do `schedule:migrate-legacy`) |
| `platform_settings` | toggle global por plataforma (youtube, tiktok, tiktok_official, instagram, facebook, kwai) |
| `schedule_slots` | agenda em banco: data+hora+vídeo, claim do dispatcher |
| `social_accounts` | credenciais por plataforma (OAuth do YT, cookies do TT) |
| `videos` | vídeos longos enviados em /upload: ciclo `awaiting_upload → uploaded → packaging → ready` + metadados do HLS |
| `youtube_shorts` | estoque; ciclo `ready_at` → `processed_video_path` → `posted_*_at` |
| `social_posts` | ledger por (slot, plataforma) — status por plataforma na /agenda |
| `processing_jobs` | estado do pipeline reencode/template |
| `service_logs` / `service_heartbeats` | observabilidade |

## Telas (layout navbar; design em docs/designs/)

| Rota | Componente | Função |
| --- | --- | --- |
| `/meus-videos` | `App\Livewire\Videos\Index` (+ `TemplateEditor`) | estoque com tabs Disponíveis (Baixados/Prontos), Editor de template, Com template, Postados; postagem instantânea; novo download |
| `/agenda` | `App\Livewire\Schedule\Index` | kanban semanal de slots (rascunho + "Salvar agenda"), picker de vídeo, drag&drop, "Gerar semana", "Forçar agora", visão Mês, toggles por plataforma |
| `/upload` | `App\Livewire\Upload\Index` | envio de vídeo longo (multipart direto pro MinIO, com retomada) |
| `/meus-uploads` | `App\Livewire\Uploads\{Index,Show}` | biblioteca dos vídeos longos + player HLS adaptativo |
| `/contas` | `App\Livewire\Accounts\Index` | cards de contas (TikTok email/senha + status de sessão; YouTube OAuth) com toggle por conta |
| `/observabilidade` | `App\Livewire\Observability\Index` | logs + heartbeats dos microserviços |

Redirects legados: `/downloads` → `/meus-videos`; `/microservices` → `/observabilidade`.

## Comandos artisan

| Comando | O que faz |
| --- | --- |
| `youtube:download-shorts <canal> [--limit=N]` | baixa Shorts do canal pra MinIO + banco |
| `schedule:migrate-legacy` | one-shot do deploy: materializa `schedule_slots` da agenda legada |
| `auto-post:check-missed` | alerta slots pulados/sem vídeo/falha total (10 min) |
| `observability:check-heartbeats` | alerta serviço sem heartbeat > 90s (1 min) |
| `uploads:prune-stale` | aborta uploads multipart abandonados > 24h (diário) |
| `tiktok:import-cookies-from-file` | fallback de emergência: importa cookies do filesystem |
| `posts:migrate-tiktok` | one-shot histórico (tiktok_posts → social_posts) |

Cron: `* * * * * php artisan schedule:run` + worker de fila
(`queue:listen --queue=posting,processing,default --tries=1 --timeout=1800`).

## Idioma do código (PROIBIDO usar pt-BR)

- Nomes de **pastas, namespaces, classes, métodos, propriedades, variáveis,
  funções, migrations, colunas de tabela, env vars, config keys** — tudo
  em **inglês**. Ex.: `App\Livewire\Schedule\Index` (não `Agenda`).
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
  `components/sidebar.blade.php` (fonte ÚNICA de navegação —
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
| download-shorts | 8770 | FastAPI + yt-dlp | `POST /shorts/download {channel_url, webhook_url}` → 202; 1 webhook/item; sobe direto pro MinIO (exceção da regra S3) |
| tiktok-uploader | 8090 | Node 22 + Playwright | `POST /posts` multipart {video, cookies, title, hashtags, webhook_url} → **202 {job_id}**; fila serial em memória; webhook `{job_id, status, session_status, refreshed_cookies?}`; `POST /session`, `POST /login`, `GET /health` |
| video | 8790 | Node 22 + ffmpeg + sharp | **todo o ffmpeg da aplicação**: três endpoints, três filas independentes. `POST /reencode` multipart {video, video_id?} → binário `_HQ` (X-Reencode: completed) ou JSON `skipped` (síncrono, sem S3). `POST /package` JSON {video_key, output_prefix, webhook_url} → 202 {uuid}; HLS/ABR (360p/720p/1080p, fMP4, segmentos de 6s); lê/escreve MinIO direto (exceção da regra S3); webhook `{uuid, status: done\|failed\|rejected\|progress, ...}`. `POST /videos` multipart {file, variants, caption_position, channel_name, channel_handle, webhook_url} → 202 {uuid}; render de legenda karaokê + template; webhook `{uuid, status: done\|failed, files}`; output em `GET /videos/{uuid}/output/{variant}`. `API_TOKEN` opcional |
| transcriber | 8780 | FastAPI + faster-whisper (CUDA) | **só transcreve**: `POST /transcribe` multipart {audio: wav mono 16kHz} → `{segments: [{start, end, text, words: [{word, start, end, score}]}], language}`. Chamado pelo `video`, não pelo Laravel |
| GenerateClips | 8765 | — | fora do fluxo atual (não entra no `make up`) |

Todos com observabilidade (logs + heartbeat → Laravel) quando
`OBSERVABILITY_URL`/`OBSERVABILITY_TOKEN` configurados.

## Rodar tudo

```bash
make setup   # 1ª vez: deps + .env de tudo (Laravel + 4 serviços)
make up      # sobe Laravel (serve/queue/pail/vite) + download-shorts +
             # tiktok-uploader + video + transcriber — sem docker
```

- Laravel → microserviço: `127.0.0.1:<porta>`; microserviço → Laravel:
  `127.0.0.1:8000` em dev, domínio real (nginx/HTTPS) em prod.
- Transcriber precisa de GPU/CUDA. Em macOS o render do template roda normal
  no `Video` (ffmpeg/libx264), mas jobs COM legenda falham gracioso na
  transcrição → `processing_jobs.failed` + Discord.
- Prod: pm2/systemd por serviço (só o TikTokUploader tem
  `ecosystem.config.cjs` por enquanto).

## Runbook de deploy desta refatoração

1. `php artisan migrate`
2. `php artisan schedule:migrate-legacy` (senão nada posta)
3. Setar `OBSERVABILITY_TOKEN` no Laravel + nos `.env` dos 4 serviços (o
   webhook do TikTok também autentica por ele — sem token, post não fecha)
4. Conferir `TIKTOK_POST_WEBHOOK_URL` (em prod: domínio real, não `:8000`) e
   `TIKTOK_POST_API_TOKEN` = `API_TOKEN` do uploader. Worker SEMPRE
   `queue:listen` (o `once()` dos toggles não é limpo em `queue:work` daemon)
5. Revisar `/agenda` (atribuir vídeos aos slots) e toggles em `platform_settings`
6. ⚠️ Rotacionar a chave Roboflow e o webhook Discord que estavam commitados
   no `.env.example` antigo do TikTokUploader (continuam no histórico git)

## Agentes e contexto

- Agente especializado no projeto: `.claude/agents/moneyclips-expert.md`
  (arquitetura, convenções e workflow de verificação — use para qualquer
  feature/refactor/review neste repo).
- Contexto completo da refatoração de 07/2026 (decisões, incidentes de CI e
  lições): [REFACTORING.md](REFACTORING.md).

## Histórico (apagados nesta refatoração)

- Integração TikTok assíncrona antiga: `TiktokPostService`,
  `TIkTokUploaderClient`, `TikTokPostDispatcher`,
  `TiktokPostCallbackController` (+ rota `/api/tiktok-posts/callback`).
- `WindowSchedule` (horários fixos + minuto crc32) e `StockReservation`
  (sorteio) — substituídos por `schedule_slots` + dispatcher por slot.
- `MicroserviceMonitor` (logs via Docker socket) e a tela `/microservices`.
- Telas `App\Livewire\Downloads\*` (viraram `/meus-videos`).
- Colunas `users.auto_post_{youtube,tiktok}_enabled` → `platform_settings`.
- Reencode por chave S3 + fila em memória + webhook → multipart síncrono.
