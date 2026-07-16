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
o resultado de volta. Exceção: `download-shorts` (produtor de vídeo) sobe
direto pro MinIO.

## Domínio: agenda em banco + estoque

```
download-shorts (FastAPI) → MinIO + youtube_shorts (estoque)
  → /meus-videos: revisão (título/hashtags) → ready_at
      → opcional: reencode (síncrono) OU template via AutoCaption (assíncrono)
  → /agenda: schedule_slots (data+hora+vídeo) → AutoPostDispatcher (cron)
      → claim atômico do slot → 1 job PostSlotToPlatform por plataforma habilitada
          → YoutubePoster (Data API) / TiktokPoster (multipart síncrono) / stubs
```

### Agenda (`schedule_slots` + `App\Services\AutoPost\`)

- `ScheduleSlot` — slot concreto: `slot_date` + `slot_time` (fuso
  `America/Sao_Paulo`, constante `AutoPost::TIMEZONE`), `youtube_short_id`
  (null = vazio), `is_active`, `dispatched_at` (claim atômico). Máx. 5/dia.
  `scheduledAt()` é o único ponto que combina data+hora (fuso).
- `AutoPostDispatcher` — a cada minuto busca slots devidos (tolerância
  `GRACE_MINUTES = 5`), reivindica via `UPDATE ... WHERE dispatched_at IS NULL`
  e enfileira `PostSlotToPlatform` (fila `posting`, `tries=1` — repost às
  cegas arrisca duplicado). O tick nunca posta nada.
- `PosterRegistry` (singleton no `AppServiceProvider`) — 1 Poster por
  plataforma implementando `Posters\PosterContract`
  (`post(PostTask): PosterResult`; outcomes `ok|dry-run|restricted|failed`).
  Toggles em **`platform_settings`** (tela /agenda). Stubs prontos:
  `TiktokOfficialPoster`, `InstagramReelsPoster`, `FacebookReelsPoster`,
  `KwaiPoster` (docblocks apontam a API alvo; credenciais irão em
  `social_accounts`).
- `SlotStatus` — status de exibição computado na leitura (nunca persistido):
  `empty|paused|future|next|due|skipped` e, pós-despacho, agregado das
  `social_posts` do slot: `posting|posted|partial|failed` (por plataforma).
- `WeekGenerator` — "Gerar semana": copia horários da última semana com slots
  (fallback: agenda legada `users.auto_post_schedule`, depois
  `AutoPost::DEFAULT_TIMES`) e auto-atribui vídeos prontos (FIFO `ready_at`).
- `StockAlert` — 1×/dia compara estoque pronto × slots vazios de 7 dias.
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
  `processing`): MinIO → multipart `POST /reencode` (síncrono, resposta =
  binário `_HQ` ou JSON `skipped`) → MinIO → `processed_video_path`.
- `VideoProcessingService::startTemplateRender()` → `StartTemplateRenderJob`:
  MinIO → multipart `POST /videos` no AutoCaption (com `webhook_url`) →
  webhook `POST /api/autocaption/webhook` → `FetchTemplateOutputJob` baixa o
  variant e grava no MinIO. Estilos: `TemplateStyle` (Claro/Escuro/Vertical →
  variants `template_white|template_black|vertical` do AutoCaption).
- 1 job pendente por vídeo (guard em `processing_jobs`).

### YouTube

- `App\Services\Youtube\ShortsPoster` — upload resumível na YouTube Data API
  v3 (HTTP puro). Credenciais em `social_accounts` (platform=`youtube`, OAuth
  Google, refresh via `YoutubeTokenRefresher`). Connect em `/contas`.

### TikTok (não-oficial, Playwright)

- **Integração NOVA (síncrona)**: `App\Services\TikTok\TiktokUploaderClient`
  faz `POST /posts` **multipart** (`video` binário + `cookies` JSON + `title`
  + `hashtags`) e recebe o desfecho na resposta:
  `{status: completed|dry-run|restricted}`; `401` = cookies inválidos →
  `SessionInvalidException` → conta marcada `session_status=invalid` +
  Discord (o poster curto-circuita até renovar em /contas). Timeout 1500s
  (verificação de conteúdo pode levar ~15 min) — roda só dentro do job de fila.
- Cookies vivem **criptografados no banco**: `social_accounts.cookies`
  (cast `encrypted:array`). Não existe mais webhook de callback nem
  `refreshed_cookies` — renovação de sessão é manual em `/contas`
  (fallback de emergência: `tiktok:import-cookies-from-file`).
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
| `youtube_shorts` | estoque; ciclo `ready_at` → `processed_video_path` → `posted_*_at` |
| `social_posts` | ledger por (slot, plataforma) — status por plataforma na /agenda |
| `processing_jobs` | estado do pipeline reencode/template |
| `service_logs` / `service_heartbeats` | observabilidade |

## Telas (layout navbar; design em docs/designs/)

| Rota | Componente | Função |
| --- | --- | --- |
| `/meus-videos` | `App\Livewire\Videos\Index` (+ `TemplateEditor`) | estoque com tabs Disponíveis (Baixados/Prontos), Editor de template, Com template, Postados; postagem instantânea; novo download |
| `/agenda` | `App\Livewire\Schedule\Index` | kanban semanal de slots (rascunho + "Salvar agenda"), picker de vídeo, drag&drop, "Gerar semana", "Forçar agora", visão Mês, toggles por plataforma |
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
- Pint impõe `mb_*` (`mb_trim`, `mb_rtrim`); `ext-mbstring` no `composer.json`.
  O `ordered_class_elements` do pint.json NÃO ordena métodos de propósito —
  a ordem é manual (regra abaixo).
- PHPStan nível max (`larastan` + bleeding-edge).
- Migrations consolidadas — em dev, prefira editar a migration de criação
  em vez de empilhar pequenas. Em prod, faça migration nova de drop/alter.

### Livewire / Blade (front)

- Tela = `Route::view()` → blade wrapper (`resources/views/<area>/index.blade.php`
  com `<x-layout layout="navbar">` + `<livewire:...>`) → componente
  `App\Livewire\<Area>\Index`.
- **PROIBIDO `@php` em blade.** Lógica/formatos/labels/datas vêm prontos do
  `render()` (view-models). Classes condicionais SEMPRE via `@class([...])` —
  nunca ternário dentro de `class=""`. Mapas de cor por status viram strings
  de classe no componente, aplicadas com `@class([$x => true])`.
- Ordem de métodos no componente: `mount()` primeiro → ações públicas →
  helpers privados → **`render()` por último**.
- Propriedade pública = fronteira de confiança: valide/saneie nas ações.
- Reuse antes de escrever: `App\Support\Hashtags` (hashtag ⇄ input),
  `App\Jobs\Concerns\TransfersStorageFiles` (MinIO ⇄ tmp), componentes
  `x-ui.toggle`, `x-ui.server-modal` (modal @if server-driven),
  `x-ui.modal` (Alpine), `x-log-level-badge`, e
  `App\View\Components\NavbarItems` (fonte ÚNICA de navegação —
  navbar + drawer mobile).

## Qualidade / CI

```bash
composer check      # phpstan + lint + pest — é o que o CI roda (tests.yml)
composer lint       # pint + rector — ambos APLICAM fixes (commite o resultado)
```

## Microserviços (MicroServices/ — todos nativos, sem docker)

| Serviço | Porta | Stack | Contrato |
| --- | --- | --- | --- |
| download-shorts | 8770 | FastAPI + yt-dlp | `POST /shorts/download {channel_url, webhook_url}` → 202; 1 webhook/item; sobe direto pro MinIO (exceção da regra S3) |
| tiktok-uploader | 8090 | Node 22 + Playwright | `POST /posts` multipart {video, cookies, title, hashtags} → SÍNCRONO `{status}`; `POST /session`, `POST /login`, `GET /health` |
| reencode | 8790 | Node 22 + ffmpeg | `POST /reencode` multipart {video, video_id?} → binário `_HQ` (X-Reencode: completed) ou JSON `skipped`; 1 ffmpeg por vez; `API_TOKEN` opcional |
| autocaption | 8780 | FastAPI + WhisperX (CUDA) | `POST /videos` multipart {file, variants, caption_position, channel_name, channel_handle, webhook_url} → 202 {uuid}; webhook `{uuid, status: done|failed}`; output em `GET /videos/{uuid}/output/{variant}` |
| GenerateClips | 8765 | — | fora do fluxo atual (não entra no `make up`) |

Todos com observabilidade (logs + heartbeat → Laravel) quando
`OBSERVABILITY_URL`/`OBSERVABILITY_TOKEN` configurados.

## Rodar tudo

```bash
make setup   # 1ª vez: deps + .env de tudo (Laravel + 4 serviços)
make up      # sobe Laravel (serve/queue/pail/vite) + download-shorts +
             # tiktok-uploader + reencode + autocaption — sem docker
```

- Laravel → microserviço: `127.0.0.1:<porta>`; microserviço → Laravel:
  `127.0.0.1:8000` em dev, domínio real (nginx/HTTPS) em prod.
- AutoCaption precisa de GPU/CUDA pro pipeline completo (em macOS ele sobe,
  mas o render falha gracioso → `processing_jobs.failed` + Discord).
- Prod: pm2/systemd por serviço (só o TikTokUploader tem
  `ecosystem.config.cjs` por enquanto).

## Runbook de deploy desta refatoração

1. `php artisan migrate`
2. `php artisan schedule:migrate-legacy` (senão nada posta)
3. Setar `OBSERVABILITY_TOKEN` no Laravel + nos `.env` dos 4 serviços
4. Revisar `/agenda` (atribuir vídeos aos slots) e toggles em `platform_settings`
5. ⚠️ Rotacionar a chave Roboflow e o webhook Discord que estavam commitados
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
