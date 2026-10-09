# MoneyClips

Plataforma de **produção de Shorts** a partir de vídeos longos (upload/import
→ cortes → edição 9:16 → estoque). Laravel orquestra; microserviços fazem o
trabalho pesado (download, transcrição, corte/render de vídeo). **A postagem
automática está sendo refeita**: o núcleo (agenda em `social_posts`,
`posts:dispatch`, `PublishPostJob`) e os três providers nativos existem:
YouTube Data API (`YoutubePostService`), microserviço TikTokUploader
(`TikTokUploaderPostService` + webhook `/api/webhook/tiktok-post`) e TikTok
Content Posting API oficial (`TikTokPostService`, Login Kit em `/contas`) —
ver `docs/agendamento.md`. **Tudo roda nativo — sem Docker**
(`make up`).

## Stack

- **PHP 8.4+ / Laravel 13+** (`bootstrap/app.php`)
- **Livewire 4 + Tailwind 4 + Vite** — kit próprio de componentes Blade em
  `resources/views/components/ui/` (sem Flux UI). Toasts: trait
  `App\Livewire\Concerns\WithToasts` → `components/ui/toasts.blade.php`.
- **MySQL** (`DB_CONNECTION=mysql`) + **fila em banco** (`QUEUE_CONNECTION=database`,
  filas nomeadas: `posting` (`PublishPostJob`, worker próprio pra um upload
  longo não travar o resto) e `processing` — o `make up` sobe dois workers:
  `--queue=processing,default` e `--queue=posting`, ambos `--timeout=1800`)
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
   `hls/*`, `videos/*` e `assets/*` — este último só leitura: o `/reframe` lê
   figurinhas/sons/memes do estoque (overlays/SFX); só o Laravel grava).

O **upload** também não passa pelo Laravel: o browser envia direto pro MinIO por
multipart presigned (o Laravel só assina as partes e confere o resultado), o que
contorna `upload_max_filesize`/`post_max_size` e dá retomada em arquivos de GBs.

## Domínio: produção de cortes + estoque

```
/upload (arquivo ou URL do YouTube) → videos + HLS → transcrição (media)
  → /meus-uploads/{video}: cortes (video_cuts) → clip frame-exato (video /cut)
      → /editor-de-video/{cut}: crop 9:16 por keyframes (à mão OU gerados pelo
        face tracking do media) → render (video /reframe)
          → youtube_shorts (estoque em /meus-videos, revisão título/hashtags)
media /shorts/download → youtube_shorts direto (Shorts prontos de um canal)
```

Postagem: `social_posts` (1 linha por Short × conta, horário na linha) →
`posts:dispatch` a cada minuto (claim atômico) → `PublishPostJob` (1 tentativa,
nunca reposta às cegas) → provider da conta (`youtube_api` síncrono; `tiktok_uploader` assíncrono, fechado
pelo webhook `/api/webhook/tiktok-post`; `tiktok_official` síncrono com
polling do status). Detalhes em `docs/agendamento.md`.

## Organização de código (Services por integração)

- **Sufixo obrigatório no nome da classe**: `*Service`, `*Interface`, `*Data`
  (DTOs), `*Enum`, `*Job`, `*Cast`, `*Exception`, `*Controller`, `*Command`.
  SOLID simples — sem camadas de clean architecture.
- **A arquitetura é específica de cada serviço, não geral da aplicação**:
  interface/DTOs vivem NA PASTA do serviço dono (ex.: `MultipartUploadInterface`
  e os `*Data` em `app/Services/Upload/`). NÃO existem pastas gerais tipo
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
  em sua pasta: `API/Youtube/` (Data API v3 + OAuth; `YoutubePostService` =
  provider `youtube_api`, upload resumable + refresh do token), `API/TikTok/`
  (Login Kit `TikTokAccountConnectorService` + `TikTokPostService` = provider
  `tiktok_official`, Content Posting API; envs `TIKTOK_CLIENT_KEY`,
  `TIKTOK_CLIENT_SECRET`, `TIKTOK_REDIRECT_URI`, `TIKTOK_APP_AUDITED`), `API/Discord/` (webhook
  de alertas) e `API/Claude/` (`claude -p` na assinatura Max, saída
  estruturada; `CLAUDE_CLI_BIN` com caminho absoluto, log no canal `claude`).
- **`app/Services/Posting/`** — núcleo da postagem: `PostProviderInterface`
  (um provider por `social_accounts.provider`, escolhido por
  `PostProviderEnum::service()`), `PostResultData`, `PostCloserService`
  (fecha o post — job e webhooks) e `PostSchedulerService` (próximo horário
  livre da grade `config/posting.php`).
- **Clients de microserviço** na raiz de Services:
  `app/Services/{DownloadYoutube,Video,TikTokUploader}/` — `TikTokUploader`
  é o provider `tiktok_uploader` (:8090); `Video` concentra os
  clients do serviço `video` (:8790) e da transcrição (`CutRenderService`,
  `VideoCutEditRenderService`, `TranscribeService` — este último aponta pro
  `media`, :8770); `DownloadYoutube` aponta pro `Media` (:8770). O client de
  HLS (`HLSPackagerService`) vive em `app/Services/Upload/HLS/`.
- **Fuso horário**: `config/app.php` já define `America/Sao_Paulo` — NUNCA
  repita o timezone em código (`now()`/`CarbonImmutable::now()` já resolvem).
  `Date::use(CarbonImmutable::class)` é global (`AppServiceProvider`).

### Estoque (`youtube_shorts` + `/meus-videos`)

Ciclo: baixado (`video_path`) → revisado/pronto (`ready_at`). As colunas
`posted_youtube_at`/`posted_tiktok_at` alimentam a tab "Postados"; o
`PublishPostJob` grava a da plataforma quando o post sai.
`template_rendered_at` alimenta a tab "Com template" (histórico — o pipeline
de template foi removido).
`postableVideoPath()` prefere `processed_video_path` legado, quando existe.

### YouTube (contas + downloads + postagem)

- Connect OAuth em `/contas` via `YoutubeAccountConnectorService`
  (credenciais em `social_accounts`, platform=`youtube`, revincular volta
  `session_status` pra `valid`). Postagem: `YoutubePostService`
  (`videos.insert` resumable; projeto Google não verificado → `private`, a
  menos que `GOOGLE_YOUTUBE_APP_VERIFIED=true`; token revogado marca a conta
  `invalid`). Download de canal (client do microserviço):
  `App\Services\DownloadYoutube\{DownloadShortsService,
  DownloadYoutubeImportService}`. Import de vídeo longo por URL (tela
  /upload): `App\Services\Upload\DownloadYoutubeService`
  → `StartYoutubeDownloadJob` → webhook `/api/webhook/download-video`
  (autenticado) fecha com claim `downloading → uploaded` e despacha o
  `StartHLSPackagingJob` — dali em diante é o fluxo normal de upload.
- Cookies do TikTok seguem **criptografados no banco**
  (`social_accounts.cookies`, cast `encrypted:array`): o
  `TikTokUploaderPostService` manda pro microserviço e o webhook grava os
  `refreshed_cookies` e o `session_status`; nunca vão pra log. Não
  existe senha de login: `login_email`/`login_password` foram dropadas e o
  /contas nunca hidrata segredo numa propriedade Livewire (cookies só entram).

## Permissões e escopo

Detalhe em `docs/permissoes.md`. Resumo:

- Papéis (spatie/laravel-permission, `App\Enums\RoleEnum`): `admin` (vê tudo;
  usuários anteriores aos papéis viram admin UMA vez na migration
  `promote_existing_users_to_admin`; `Seeder001Roles` só cria papéis/permissões) e `creator`
  (usuário novo do Google OAuth; só o que tem `user_id` dele).
  `User::isAdmin()` é o único ponto que pergunta pelo papel.
- Permissões (`App\Enums\PermissionEnum`, só onde papel não basta, todas do
  admin): `observability.view` (`/observabilidade` + navbar), `logs.view`
  (`Gate viewLogViewer` do log-viewer), sem `assets.review`: a revisão de `stock_assets` é só por artisan.
- **Listagem filtra por scope, ação em registro único checa Policy**: trait
  `App\Models\Concerns\BelongsToUser` (`user()` + `forUser(User)`) em
  `Video`/`YoutubeShort`/`SocialAccount`; policies padrão em `app/Policies/`;
  403 em pt-BR mapeado em `bootstrap/app.php`. Sem global scope.
- Rota `{video:uuid}` alheia → 404 (`resolveRouteBinding`); tela `Route::view`
  alheia → 403 (policy no `mount()`). `stock_assets` é global (só admin revisa).
- Sanctum e laravel-auditing ficam instalados pro SaaS; nenhuma API criada.

## Observabilidade

Push HTTP dos microserviços pro Laravel — sem Docker socket, sem Loki:

- `POST /api/observability/logs` (lote), autenticado por
  `X-Observability-Token` (`OBSERVABILITY_TOKEN`, fail-closed). Tabela
  `service_logs` (prune 14 dias).
- Tela `/observabilidade` (`App\Livewire\Observability\Index`): stream de
  logs (filtros por serviço/level + busca, poll 3s) + drawer de detalhe.
- Lado dos serviços: `RemoteObservability.ts` (Node) / `observability.py`
  (Python) — decoram o logger local (buffer, flush 2s/20 linhas,
  fire-and-forget). Envs: `OBSERVABILITY_URL`, `OBSERVABILITY_TOKEN`, `SERVICE_NAME`.

## Banco de dados (visão geral)

| Tabela | Papel |
| --- | --- |
| `users` | login Google OAuth; papel `admin`/`creator` via spatie (`model_has_roles`) |
| `social_accounts` | credenciais por plataforma (OAuth do YT e do TikTok oficial, cookies do TT); `platform` = onde posta, `provider` (`PostProviderEnum`) = por onde (padrão pela plataforma: `youtube_api`, `tiktok_uploader`; `tiktok_official` vem do Login Kit); `session_status=invalid` = token revogado/cookies mortos (o dispatcher falha na hora) |
| `social_posts` | 1 linha por Short × conta (unique): `scheduled_for`, `status` (`PostStatusEnum` scheduled→posting→published\|failed\|missed\|canceled), `privacy`, `external_id`, `url`, `error`, `attempts`. Sem `user_id`: o dono é o da conta (`SocialPost::forUser($user)`, admin vê tudo) |
| `videos` | vídeos longos enviados em /upload (arquivo ou URL do YouTube): ciclo `awaiting_upload\|downloading → uploaded → packaging → ready` + metadados do HLS |
| `youtube_shorts` | estoque; ciclo `ready_at` → `posted_*_at`; `user_id` nullable (dono do vídeo longo no corte editado; null = short de canal, só admin vê) |
| `stock_assets` | banco curado de sfx/emoji/imagem/meme (uuid, `kind`, `license`, `status` pending→approved\|disabled; `duration_ms`/`width`/`height` lidos no add via ffprobe/getimagesize, nullable; `author` = crédito CC BY); binário em `assets/<kind>/<uuid>.<ext>` no MinIO, nunca no git; memes exigem `own_risk` |
| `service_logs` | observabilidade (logs dos microserviços) |

## Telas (layout navbar; design em docs/designs/)

| Rota | Componente | Função |
| --- | --- | --- |
| `/meus-videos` | `App\Livewire\Videos\Index` | estoque com tabs Disponíveis (Baixados/Prontos), Com template, Postados (histórico); novo download |
| `/upload` | `App\Livewire\Uploads\Create` | envio de vídeo longo (multipart direto pro MinIO, com retomada) OU import por URL do YouTube (valida + preview → download no microserviço) |
| `/meus-uploads` | `App\Livewire\Uploads\{Index,Show}` | biblioteca dos vídeos longos + player HLS adaptativo; corte manual e busca de momentos por IA (`SuggestCutsJob` → cortes com `is_ai_generated`) |
| `/editor-de-video/{cut}` | `App\Livewire\VideoEditor\Index` | reframe do corte por keyframes (crop 9:16, modos, legendas) + "Gerar tracking automático" (face tracking no `media`, sobrescreve os keyframes) + "Gerar corte editado" → render no serviço `video` → estoque de `/meus-videos` |
| `/contas` | `App\Livewire\Accounts\Index` | cards de contas (TikTok por cookies de sessão, só de escrita — a tela mostra "sessão salva em DATA" e nunca devolve o valor; TikTok oficial via Login Kit; YouTube OAuth) com toggle por conta (conta desativada não posta) |
| `/observabilidade` | `App\Livewire\Observability\Index` | stream de logs dos microserviços |

Não existe redirect legado: cada tela tem UMA rota. Link novo aponta pra rota
final — nada de `Route::redirect` pra não mexer na navbar.

## Comandos artisan

| Comando | O que faz |
| --- | --- |
| `uploads:prune-stale` | aborta uploads multipart abandonados > 24h (diário) |
| `posts:dispatch` | a cada minuto: agendado com atraso > `grace_minutes` vira Missed, Posting sem resposta > `stuck_minutes` vira Failed, o que chegou na hora é reivindicado e vai pro `PublishPostJob` |
| `assets:add-file {path}` / `assets:add-dir {path}` | sobe pro MinIO e cria `stock_assets` pending (`--kind --license --tags --emotion --source-url --author --real-person --has-audio --risk-note`) |
| `assets:review` | aprova/recusa os pending um a um, com aviso de risco (pessoa real/áudio) |
| `assets:disable {id}` | tira o asset de circulação |

Cron: `* * * * * php artisan schedule:run` + dois workers de fila
(`queue:listen --queue=processing,default --tries=1 --timeout=1800` e
`queue:listen --queue=posting --tries=1 --timeout=1800`).

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
- **Tutorial guiado**: passos por nome de rota em `config/tour.php`
  (`target`, `title`, `body`, `advance: click` opcional), ancorados por
  `data-tour="<target>"` no elemento da tela. O `AppLayout` monta o
  `x-ui.tour` (classe `resources/js/Tour/GuidedTour.ts`) só na rota que tem
  passos; abre sozinho na 1ª visita (localStorage `tour.<rota>`), pula passo
  cujo alvo não está na tela e reabre pelo "Ver tutorial" do menu do usuário.
  `advance: click` libera o clique no alvo e avança com ele — só em
  navegação, nunca em botão que dispara ação que custa (Buscar, Gerar,
  Editar com IA, Importar).

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
| media | 8770 | FastAPI + yt-dlp + faster-whisper | **único serviço Python** (download + transcrição, filas separadas). `POST /shorts/download {channel_url, webhook_url}` → 202; 1 webhook/item. `GET /videos/metadata?url=` → dados do vídeo (400 URL inválida/live, 404 indisponível). `POST /videos/download {url, video_uuid, video_key, webhook_url}` → 202; fila de 1 consumidor baixa em ≤1080p (fallback progressivo de formato), sobe na key EXATA e ecoa `{video_uuid, status: completed\|failed, size_bytes, ...}` com `X-Observability-Token`. Sobe direto pro MinIO (exceção da regra S3). `POST /transcriptions` multipart {audio, uuid, webhook_url} → 202 {job_id}; fila própria + `gpu_lock` (faster-whisper, CUDA em prod, cpu/int8 no macOS); webhook `{uuid, status: done\|failed, transcript: {segments: [{start, end, text, words: [{word, start, end, score}]}], language}}`. Chamado pelo `video` (template) E pelo Laravel (vídeo longo e cortes). `POST /face-tracking` multipart {video, uuid, webhook_url, max_keyframes, style?: smooth (default) | cuts (troca seca + zoom-base + YuNet/SFace)} → 202 {job_id}; fila própria + o MESMO `gpu_lock` da transcrição (MediaPipe e faster-whisper não dividem GPU); webhook `{uuid, status: done\|failed, keyframes: [{t, mode: vertical, regions: [{x,y,w,h}]}], speakers: [{start, end, speaker}], source: {width, height, duration}}` |
| tiktok-uploader | 8090 | Node 22 + Playwright | consumido pelo `TikTokUploaderPostService` (provider `tiktok_uploader`; envs `TIKTOK_UPLOADER_URL`, `TIKTOK_UPLOADER_API_TOKEN`, `TIKTOK_UPLOADER_WEBHOOK_URL`). `POST /posts` multipart {video, cookies, title, hashtags, webhook_url, account_id?} → **202 {job_id}**; fila serial em memória; webhook `{job_id, status: completed\|dry-run\|restricted\|failed, session_status, refreshed_cookies?}` → `/api/webhook/tiktok-post` com `X-Observability-Token`; `POST /session`, `POST /login`, `GET /health` |
| video | 8790 | Node 22 + ffmpeg + sharp | **todo o ffmpeg da aplicação**: cinco endpoints, filas independentes. `POST /reencode` multipart {video, video_id?} → binário `_HQ` (X-Reencode: completed) ou JSON `skipped` (síncrono, sem S3). `POST /package` JSON {video_key, output_prefix, webhook_url} → 202 {uuid}; HLS/ABR (360p/720p/1080p, fMP4, segmentos de 6s); lê/escreve MinIO direto (exceção da regra S3); webhook `{uuid, status: done\|failed\|rejected\|progress, ...}`. `POST /cut` JSON {cut_uuid, video_key, start_seconds, end_seconds, clip_key, audio_key, webhook_url} → 202 {uuid}; corte frame-exato (cap 1080p) + WAV pra transcrição; webhook `{uuid, cut_uuid, status: done\|failed, audio}`. `POST /reframe` JSON {edit_uuid, source_key, output_key, keyframes, settings, transcript?, webhook_url} → 202 {uuid}; render do corte editado em 1080x1920 (ignora `source`; zoompan por keyframes + legenda opcional); `settings.speakerColors` ({id: hex}) + `transcript.segments[].speaker` pintam a legenda por locutor via tag inline do `.ass` — sem os dois, saída byte a byte igual à antiga; `captions[]` ({t:[a,b], text, style?: speech\|shout\|punch\|aside\|note\|art, pos?: bottom\|top}) troca o karaokê pela legenda literal (1 Dialogue por bloco, Montserrat embarcada em `assets/fonts` via `fontsdir`), com `caption_preset?` (verde\|branco_italico\|branco_limpo) e `watermark?` (handle, 5s a cada 40s) — sem `captions[]`, saída idêntica; `overlays[]` ({key, t:[a,b], kind: card\|small\|emoji\|meme\|meme_clip, pos?:{x,y}}) e `sfx[]` ({key, t, gain_db?}) opcionais, até 40 cada, keys só em `assets/` (422 fora disso): 1 input com janela por aparição + `amix normalize=0`; o áudio do `meme_clip` vai mandando a mesma key em `sfx[]`; sem os dois, saída idêntica; `cuts?` ([[a,b]] a REMOVER, em segundos do clip, ordenados e sem sobreposição) e `dead_air?` (bool: pausas de 0.5–1.5s pelo silencedetect −35dB viram corte com 0.12s de folga) ligam o jump cut: keep = [0,dur] − cuts − ar morto, trim/atrim + concat a/v no mesmo graph, fade de 30ms nas emendas, loudnorm I=-14, degrau de zoom 1.3× (abre se a região cabe, fecha se não) na emenda sem troca de enquadramento; pausa que cruza caption `note` ou overlay `meme_clip` não é cortada; keyframes/captions/overlays/sfx seguem em tempo do clip e a `Timeline` remapeia — sem os dois, saída idêntica (`cuts: []` já liga o pipeline novo); webhook `{uuid, edit_uuid, status: done\|failed, duration_seconds?}` (duração final; o Laravel loga aviso < 60s). `POST /videos` multipart {file, variants, caption_position, channel_name, channel_handle, webhook_url} → 202 {uuid}; render de legenda karaokê + template; webhook `{uuid, status: done\|failed, files}`; output em `GET /videos/{uuid}/output/{variant}`. `API_TOKEN` opcional, obrigatório com `NODE_ENV=production` (o serviço não sobe sem); `FFMPEG_TIMEOUT_SECONDS` (padrão 1800) mata ffmpeg travado |

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

1. `php artisan migrate` (dropa `social_posts`; deploys anteriores já
   dropraram `schedule_slots`, `processing_jobs`, `service_heartbeats`)
2. `DISCORD_WEBHOOK_URL` continua o mesmo env — só a chave de config mudou
   pra `services.discord.webhook` (rodar `php artisan config:cache`)
3. Setar `OBSERVABILITY_TOKEN` no Laravel + nos `.env` dos serviços
   (prod: `NODE_ENV=production` + `API_TOKEN` = `HLS_API_TOKEN` no video)
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

## Agentes e contexto

- Agente especializado no projeto: `.claude/agents/moneyclips-expert.md`
  (arquitetura, convenções e workflow de verificação — use para qualquer
  feature/refactor/review neste repo).
- Histórico das refatorações vive no git (PRs #48, #61, #62, #63) — este
  arquivo descreve o estado ATUAL.

## Histórico (apagados nas refatorações)

- **Postagem inteira (YouTube + TikTok) + ledger `social_posts`** — será
  refeita do zero. Caíram: `app/Services/AutoPost/` (registry + DTOs),
  `PostShortToPlatformJob`, posters (`YoutubePosterService`,
  `ShortsPosterService`, `YoutubeTokenRefresherService`,
  `TiktokPosterService`), client `app/Services/TikTokUploader/`, webhook
  `/api/webhook/tiktok-posts`, postagem instantânea da /meus-videos, helper
  `Platforms`, comandos `tiktok:import-cookies-from-file` e
  `posts:migrate-tiktok`, config `services.tiktok_post`. Ficaram:
  `social_accounts` + /contas (credenciais), colunas `posted_*_at`
  (histórico) e o microserviço TikTokUploader (sem consumidor). O webhook
  do Discord agora vive em `services.discord.webhook`.

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
