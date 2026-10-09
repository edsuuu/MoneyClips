# Agendamento e postagem automática

Estado: **núcleo pronto + os três providers** (YouTube Data API, TikTokUploader
e TikTok Content Posting API). Só providers nativos, escolhidos por conta,
cada um no seu PR e nesta ordem: YouTube Data
API (`videos.insert`, OAuth que já existe em `/contas`) → TikTokUploader
(cookies) → TikTok Content Posting API oficial (direct post, privado até a
auditoria passar). Nenhuma agregadora. A tela `/agenda` e o
auto-agendamento também entram em PRs separados. Arquitetura completa: nota `Arquitetura- agendamento` (Almanac).

## Fluxo

```
Short revisado (ready_at)
  → social_posts: 1 linha por Short × conta, status Scheduled, scheduled_for = horário
  → posts:dispatch (a cada minuto): claim atômico Scheduled → Posting
  → PublishPostJob (fila posting, 1 tentativa): MinIO → tmp → provider da conta
  → Published (link + privacidade + posted_{plataforma}_at no Short)
    | Posting com external_id (provider assíncrono; o webhook fecha)
    | Failed (motivo em error)
```

Um agendador só, o nosso: nenhum provider recebe data futura. Na hora do
horário o dispatcher manda "publicar agora".

## Estados (`PostStatusEnum`)

| Estado | Rótulo | Quem leva até ele |
|---|---|---|
| Scheduled | Agendado | agendar / tentar de novo (dono) |
| Posting | Postando… | claim do `posts:dispatch` |
| Published | Postado | job (provider síncrono) ou webhook |
| Failed | Falhou | provider recusou, vídeo ausente, conta bloqueada, exceção no job, reaper |
| Missed | Perdeu o horário | passou de `grace_minutes` sem sair (servidor/worker fora do ar) |
| Canceled | Cancelado | dono |

Regras que não se negociam:

- **Nunca posta fora de hora:** Scheduled com mais de `grace_minutes` de
  atraso vira Missed, não sai.
- **Nunca reposta às cegas:** `PublishPostJob` tem `$tries = 1`; exceção no
  meio do upload vira Failed. Posting sem resposta por mais de
  `stuck_minutes` vira Failed "resultado desconhecido" (pode ter saído:
  confira na plataforma). Tentar de novo é sempre ação do dono.
- **Um post por Short × conta:** unique no banco + claim por
  `UPDATE ... WHERE status = 'scheduled'` (dois ticks ou dois workers nunca
  reivindicam a mesma linha).
- Conta desativada ou com `session_status = invalid` na hora do post → Failed
  com o motivo, na hora, com Discord.

## Horários (`config/posting.php`, tudo sobrescrevível por env)

| Chave | Env | Padrão |
|---|---|---|
| `times` | `POSTING_TIMES` (vírgula) | `08:00,10:00,14:00,17:00,20:00,21:30` (manhã, tarde e noite; nunca 12h, o pior horário medido) |
| `per_day` | `POSTING_PER_DAY` | 6 por conta |
| `min_gap_minutes` | `POSTING_MIN_GAP_MINUTES` | 60 entre posts da mesma conta |
| `grace_minutes` | `POSTING_GRACE_MINUTES` | 30 |
| `stuck_minutes` | `POSTING_STUCK_MINUTES` | 60 |

`PostSchedulerService::nextSlot($account)` devolve o primeiro horário da grade
depois de agora + 5 min, a pelo menos `min_gap_minutes` de qualquer post ativo
(Scheduled, Posting, Published) da conta e com o dia abaixo de `per_day`.
Horário em `America/Sao_Paulo` (o fuso do app). O `nextSlot` só calcula, não
reserva: quem agenda trava a conta (`lockForUpdate`) e grava o post na mesma
transação, senão dois agendamentos simultâneos pegam o mesmo horário.

## Escopo por usuário (SaaS)

`social_posts` não tem `user_id`: o dono do post é `social_accounts.user_id`.
Telas filtram com `SocialPost::query()->forUser($user)` (admin vê tudo). O
dispatcher e o job são de sistema e varrem todos os usuários, mas cada post só
sai pela **própria** conta (`$post->socialAccount`).

Pendente da integração com `feat/permissoes-e-escopo`: quando
`youtube_shorts.user_id` existir, o `PublishPostJob` recusa (Failed) post cujo
Short não tem dono ou é de outro usuário que não o dono da conta.

## Provider

`social_accounts.platform` é **onde** posta (youtube|tiktok);
`social_accounts.provider` (`PostProviderEnum`) é **por onde**. Conta nova
recebe o padrão da plataforma (`youtube_api`, `tiktok_uploader`).

Adicionar um provider:

1. Classe implementando `PostProviderInterface`
   (`post(SocialPost $post, string $localPath): PostResultData`) na pasta da
   integração: API oficial em `app/Services/API/<Plataforma>/`, microserviço
   em `app/Services/<Microserviço>/`. A legenda é
   `$post->youtubeShort->caption()` (título + hashtags sem duplicar; as tags
   soltas em `captionHashtags()`), lida na hora do post.
2. Devolver `PostResultData::published($url, 'public'|'private'|'unlisted')`,
   `::pending($externalId)` (o webhook fecha achando por `external_id`) ou
   `::failed($motivo)`. Erro conhecido da plataforma (token, cota, validação)
   vira `failed` com mensagem legível; exceção solta vira Failed "pode ter
   saído" pelo `failed()` do job.
3. Case no `PostProviderEnum` e o braço no `match` de `service()`.

| Provider | Classe | Estado |
|---|---|---|
| `youtube_api` | `App\Services\API\Youtube\YoutubePostService` | pronto |
| `tiktok_uploader` | `App\Services\TikTokUploader\TikTokUploaderPostService` | pronto (assíncrono, webhook) |
| `tiktok_official` | `App\Services\API\TikTok\TikTokPostService` | pronto, testado só com `Http::fake` (chaves pendentes) |

### YouTube Data API (`youtube_api`)

- **Token:** usa o `access_token` da conta enquanto falta mais de 1 min pro
  `token_expires_at`; senão renova no `oauth2.googleapis.com/token` com o
  `refresh_token` guardado pelo `YoutubeAccountConnectorService` e grava o
  novo token criptografado. `invalid_grant` (revogado/expirado), conta sem
  refresh token ou 401 da API → Failed "revincule" + `session_status =
  invalid` (o dispatcher passa a falhar os próximos posts da conta na hora,
  sem gastar cota). Revincular em `/contas` volta a sessão pra `valid`.
- **Upload resumable** (`videos.insert`, `part=snippet,status`): abre a sessão
  com os metadados e manda o arquivo em pedaços de 8 MiB, seguindo o `Range`
  do 308. O vídeo só nasce no canal quando o último pedaço é aceito. 5xx ou
  conexão caída num pedaço → consulta a sessão (`PUT` vazio com
  `Content-Range: bytes */<total>`, backoff 2/4/8 s, até 3 vezes no upload):
  308 retoma do `Range`, 200/201 é o vídeo criado (Published). Sem resposta
  → Failed dizendo se o vídeo pode existir (último pedaço) ou não.
- **Metadados:** título do Short (sem `<`/`>`, até 100 caracteres; vazio vira
  "Short"), descrição = `caption()` (até 5000 bytes), `tags` = hashtags sem
  `#` (até 500 caracteres), categoria 22, `selfDeclaredMadeForKids=false`.
- **Privacidade:** `social_posts.privacy` → `meta.privacy_status` da conta →
  `public`. **Projeto Google não verificado trava todo upload em private:**
  sem `GOOGLE_YOUTUBE_APP_VERIFIED=true` o post vai `private` e o log registra
  o rebaixamento. A privacidade gravada é a que o YouTube devolveu.
- **Cota/erros:** `quotaExceeded`/`rateLimitExceeded` → Failed "cota
  esgotada, volta à meia-noite do Pacífico"; `uploadLimitExceeded` → limite do
  canal; o resto → "YouTube recusou … (HTTP, reason): mensagem". Sem retry:
  reagendar é do dono.
- Link gravado: `https://www.youtube.com/shorts/{id}`. Token e refresh token
  nunca vão pra log, `error` ou Discord.

### TikTokUploader (`tiktok_uploader`, não oficial)

Risco de ban aceito pelo dono: o microserviço (`MicroServices/TikTokUploader`,
:8090) posta pelo navegador com os cookies da conta.

- **Envio:** `POST /posts` multipart `{video, cookies, title, hashtags,
  webhook_url, account_id}` → `202 {job_id}` → o post fica em Posting com
  `external_id = job_id`. `title` é o título do Short; `hashtags` as do bloco
  (`captionHashtags()`, sem repetir as do título). `Authorization: Bearer`
  com `TIKTOK_UPLOADER_API_TOKEN` quando setado (o serviço exige `API_TOKEN`
  em produção). Conta sem cookies → Failed sem chamar o serviço; 4xx/5xx →
  Failed com o detalhe; serviço inacessível → Failed (timeout pode ter
  enfileirado: conferir no TikTok).
- **Webhook** `POST /api/webhook/tiktok-post` (`X-Observability-Token`, no
  padrão dos outros webhooks) `{job_id, status, session_status,
  refreshed_cookies?, error?, detail?}`: acha o post por `external_id` (só de
  conta `tiktok_uploader`; senão 404) e
  - `completed` → Published (`privacy=public`, sem link: o uploader não
    devolve a URL) + `posted_tiktok_at`;
  - `dry-run` → Failed (nada saiu; `DRY_RUN=true` no serviço);
  - `restricted` → Failed com o motivo do TikTok;
  - `failed` → Failed; com `session_status=invalid`, "sessão expirada".
  A conta sincroniza **mesmo com o post já fechado**: `refreshed_cookies`
  substitui os cookies (cast `encrypted:array`) e carimba
  `cookies_last_validated_at`; `session_status` valid/invalid vai pra conta
  (invalid faz o dispatcher falhar os próximos posts dela até colar cookies
  novos em `/contas`). Cookies nunca vão pra log, `error`, Discord ou resposta.
- O fechamento (job síncrono ou webhook) passa por
  `App\Services\Posting\PostCloserService::close()`: UPDATE só se ainda está
  em Posting, `posted_*_at`, log e Discord num lugar só. Resposta repetida
  (webhook reenviado com o mesmo desfecho) é ignorada sem alarme; `completed`
  tardio sobre o "Resultado desconhecido" do reaper vira Published (é o
  desfecho real, nada é repostado) e fica no log; qualquer outra resposta
  tardia só avisa no Discord.

### TikTok oficial (`tiktok_official`, Content Posting API)

- **Conta:** botão "TikTok oficial" em `/contas` → `oauth/tiktok/connect`
  (Login Kit v2, `state` na sessão, escopos fixos no código
  `user.info.basic,video.publish`) → callback troca o `code` no
  `/v2/oauth/token/` e busca o nome em `/v2/user/info/`
  (`TikTokAccountConnectorService`). Grava `social_accounts` platform=tiktok,
  `provider=tiktok_official`, `external_account_id = open_id`, tokens
  criptografados, `session_status=valid`. O card mostra "API oficial (Login
  Kit)" e não tem editor de cookies.
- **Token:** access token de 24h renovado 5 min antes de vencer com o refresh
  token (365d, rotacionado e regravado). `invalid_grant`,
  `access_token_invalid`, `scope_not_authorized` ou 401 → Failed "revincule"
  + `session_status=invalid`.
- **Post** (`TikTokPostService`, direct post): `creator_info/query` →
  privacidade: **app sem auditoria só posta `SELF_ONLY`** (e só em conta
  privada), então sem `TIKTOK_OFFICIAL_AUDITED=true` vai `SELF_ONLY` (gravado
  `private`) e o log registra; auditado + público → `PUBLIC_TO_EVERYONE`. Nível
  fora de `privacy_level_options` → Failed antes do upload. Respeita
  `comment/duet/stitch_disabled` do criador. `video/init` (`FILE_UPLOAD`;
  ≤ 64 MiB num pedaço só, acima pedaços de 10 MiB e o último absorve o resto)
  → `PUT` com `Content-Range` na `upload_url` → `status/fetch` a cada 5 s
  (até 10 min, `Sleep` — fakeável; 5xx, 429 ou conexão caída no polling
  só gasta uma tentativa, o vídeo já foi enviado) até `PUBLISH_COMPLETE` (link
  `tiktok.com/@{creator_username}/video/{id}` quando público) ou `FAILED`
  (`fail_reason`). Sem confirmação em 10 min → Failed com o `publish_id`,
  "confira no app".
- **Cota/erros:** `rate_limit_exceeded`, `spam_risk_too_many_posts`,
  `spam_risk_too_many_pending_share`, `reached_active_user_cap` → Failed "Cota
  do TikTok" sem retry; `unaudited_client_can_only_post_to_private_accounts`
  → Failed explicando; o resto → "TikTok recusou … (HTTP, code): mensagem".
- Envs: `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`, `TIKTOK_REDIRECT_URI`
  (padrão `APP_URL/oauth/tiktok/callback`, cadastrar igual no portal),
  `TIKTOK_OFFICIAL_AUDITED` (bloco `services.tiktok_official`).

## Operação

- Prod: o cron `* * * * * php artisan schedule:run` roda o `posts:dispatch`.
  Em dev, `php artisan schedule:work` (o `make up` não sobe o agendador).
- Worker próprio da fila `posting` (`make up` sobe os dois), pra um upload
  longo não travar a fila `processing`.
- Logs em `daily` com `[INFO|WARN|ERRO][Posting]`; Discord em Missed, Failed e
  Published.
- Missed por servidor desligado: reagendar é do dono (tela `/agenda`, PR
  próprio).
