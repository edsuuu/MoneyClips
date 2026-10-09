# Relatório CUMULUS — postagem nativa (YouTube + TikTok)

Sessão de nuvem só com a branch `claude/post-youtube-tiktok-6u027a`, então os
três PRs pedidos viraram **três commits delimitados no mesmo PR em rascunho
(#112)**, nesta ordem e cada um verde sozinho (PHPStan max, Pint, Rector e
Pest):

| PR pedido | Commit | Assunto |
|---|---|---|
| `feat/post-youtube-api` | `2699cdc` | provider da YouTube Data API |
| `feat/post-tiktok-uploader` | `adadca5` | provider do TikTokUploader + webhook |
| `feat/post-tiktok-oficial` | o commit que traz este relatório | Login Kit em /contas + Content Posting API |

Se preferir três PRs: `git cherry-pick` de cada commit numa branch a partir da
`main` (são independentes na ordem acima).

## 1. YouTube Data API (`youtube_api`)

- `App\Services\API\Youtube\YoutubePostService` (implementa
  `PostProviderInterface`), ligado em `PostProviderEnum::service()`.
- Refresh do token OAuth com o `refresh_token` que o
  `YoutubeAccountConnectorService` já guarda (renova faltando < 1 min);
  `invalid_grant`, conta sem refresh token ou 401 → Failed "revincule" e
  `session_status=invalid` (o dispatcher falha os próximos posts na hora).
  Revincular volta a sessão pra `valid`.
- Upload resumable (`videos.insert`, pedaços de 8 MiB seguindo o `Range` do
  308). Título (≤ 100, sem `<>`), descrição = `caption()`, tags = hashtags
  sem `#` (`App\Helpers\Hashtags` — o CLAUDE.md chama de `App\Support`, mas a
  classe vive em `Helpers`), categoria 22, `selfDeclaredMadeForKids=false`.
- Privacidade do `social_posts` (fallback `meta.privacy_status` da conta →
  public). **Projeto Google não verificado força `private`**: sem
  `GOOGLE_YOUTUBE_APP_VERIFIED=true` o post vai private e o log registra; a
  privacidade gravada é a que o YouTube devolveu.
- `quotaExceeded`/`rateLimitExceeded` → Failed "cota esgotada"; o
  `uploadLimitExceeded` do canal tem mensagem própria; o resto vira "YouTube
  recusou … (HTTP, reason): mensagem". Sem retry.
- Arquivo sai do MinIO pelo `TransfersStorageFiles` (no `PublishPostJob`, já
  existente). Link gravado: `youtube.com/shorts/{id}`.
- `/contas`: o card do YouTube deixou de mostrar "Token expirado" (o access
  token vence a cada hora e é renovado sozinho) e passa a mostrar "Acesso
  revogado" quando a sessão está `invalid`. `SocialAccount::tokenExpired()`
  saiu (sem uso).
- `YoutubeShort::captionHashtags()` extraído do `caption()` (mesma regra:
  tag do título não repete) pra reuso nos providers.

## 2. TikTokUploader (`tiktok_uploader`)

- `App\Services\TikTokUploader\TikTokUploaderPostService`: `POST /posts`
  multipart `{video, cookies, title, hashtags, webhook_url, account_id}` →
  202 `{job_id}` → post fica em Posting com `external_id = job_id`. Bearer
  `TIKTOK_UPLOADER_API_TOKEN` quando setado. Conta sem cookies → Failed sem
  chamar; 4xx/5xx → Failed com o detalhe; serviço fora → Failed.
- Webhook `POST /api/webhook/tiktok-post` (`X-Observability-Token`, igual aos
  outros): `completed` → Published (public, sem link: o serviço não devolve
  URL) + `posted_tiktok_at`; `dry-run` e `restricted` → Failed (nunca
  "postado"); `failed` + `session_status=invalid` → Failed "sessão expirada".
  A conta sincroniza **mesmo com o post já fechado**: `refreshed_cookies`
  (cast `encrypted:array`) + `cookies_last_validated_at`, e `session_status`.
- O fechamento do post saiu do `PublishPostJob` para
  `App\Services\Posting\PostCloserService` (mesma lógica: UPDATE só em
  Posting, `posted_*_at`, log, Discord) e é usado pelo job e pelo webhook.
- Config `services.tiktok_uploader` (`TIKTOK_UPLOADER_URL`,
  `TIKTOK_UPLOADER_API_TOKEN`, `TIKTOK_UPLOADER_TIMEOUT`,
  `TIKTOK_UPLOADER_WEBHOOK_URL`).

## 3. TikTok oficial (`tiktok_official`)

- Login Kit em `/contas` (botão "TikTok oficial" → `oauth/tiktok/connect`,
  `state` na sessão, callback valida o state, troca o code e busca o
  `display_name`): `App\Services\API\TikTok\TikTokAccountConnectorService`.
  Conta `platform=tiktok`, `provider=tiktok_official`, `external_account_id =
  open_id`, tokens criptografados. O card mostra "API oficial (Login Kit)" e
  não abre o editor de cookies.
- `App\Services\API\TikTok\TikTokPostService`: `creator_info/query` →
  privacidade (**sem auditoria só `SELF_ONLY`**; `TIKTOK_APP_AUDITED=true`
  libera `PUBLIC_TO_EVERYONE`; nível fora das opções do criador → Failed antes
  do upload) → `video/init` `FILE_UPLOAD` → PUT em pedaços com
  `Content-Range` → `status/fetch` a cada 5 s por até 10 min até
  `PUBLISH_COMPLETE` (link quando público) ou `FAILED` (`fail_reason`).
- Token de 24h renovado com o refresh token (rotacionado e regravado);
  `invalid_grant`/`access_token_invalid`/`scope_not_authorized`/401 →
  `session_status=invalid`. Cota (`rate_limit_exceeded`,
  `spam_risk_too_many_posts`, `reached_active_user_cap`…) → Failed sem retry;
  `unaudited_client_can_only_post_to_private_accounts` com explicação.
- Config `services.tiktok`: `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`,
  `TIKTOK_REDIRECT_URI` (padrão `APP_URL/oauth/tiktok/callback`),
  `TIKTOK_SCOPES` (`user.info.basic,video.publish`), `TIKTOK_APP_AUDITED`.
- Tudo testado com `Http::fake` + `Sleep::fake` (as chaves não existem).

## Testes

`tests/Feature/Posting/{YoutubePostServiceTest,TikTokUploaderPostTest,TikTokOfficialPostTest}.php`
cobrem sucesso, token expirado (refresh), token revogado, erro da API, cota
sem retry, privacidade forçada, chunking, webhook (published/failed/dry-run/
restricted/tarde/404/401) e que **nenhum segredo vaza**: tokens e cookies
ausentes do log (listener de `MessageLogged`), do `error`, do Discord, do HTML
e do snapshot do Livewire. Suíte: 353 testes verdes, PHPStan 0 erros.

Ambiente: o proxy da sessão bloqueia o PPA do PHP, então o PHP 8.4.26 veio da
imagem oficial `php:8.4-cli` (sem intl/gd/zip/bcmath: `composer install`
com `--ignore-platform-req` pra elas; nenhum teste depende delas). O CI
(PHP 8.4 completo) é quem valida de vez. O Rector da `main` quer mexer em
`SuggestCutsJob.php` e `AdminGatesTest.php` (fora do escopo): não incluído.

## Como testar à mão

Ainda não há tela de agendamento (`/agenda` é PR próprio): crie o post no
tinker e rode o dispatcher.

```php
$account = App\Models\SocialAccount::where('platform', 'youtube')->first(); // ou a conta TikTok
App\Models\SocialPost::create([
    'youtube_short_id' => App\Models\YoutubeShort::whereNotNull('ready_at')->value('id'),
    'social_account_id' => $account->id,
    'scheduled_for' => now(),
    'status' => App\Enums\PostStatusEnum::Scheduled,
    'privacy' => 'private',
]);
```

`php artisan posts:dispatch` + worker `queue:listen --queue=posting` → olhar
`social_posts` (status/url/error), o log `daily` e o Discord.

## O que depende do dono

- **YouTube:** a verificação/auditoria do projeto Google (YouTube API
  Services). Até lá todo upload fica private; depois, setar
  `GOOGLE_YOUTUBE_APP_VERIFIED=true` + `config:cache`. A cota padrão
  (10.000 unidades/dia) limita poucos uploads por dia por projeto — pedir
  aumento junto com a auditoria. Contas já vinculadas seguem funcionando
  (refresh token guardado); se alguma der "revincule", é só revincular.
- **TikTok oficial:** criar o app no TikTok for Developers com Login Kit +
  Content Posting API (Direct Post), cadastrar o redirect URI
  (`https://<domínio>/oauth/tiktok/callback`), escopos `user.info.basic` e
  `video.publish`, e preencher `TIKTOK_CLIENT_KEY`/`TIKTOK_CLIENT_SECRET`.
  Sem auditoria o TikTok só aceita post `SELF_ONLY` **em conta privada** (a
  conta precisa estar privada no app). Depois da auditoria:
  `TIKTOK_APP_AUDITED=true`. O fluxo real nunca rodou contra a API (só
  `Http::fake`): o primeiro post de verdade merece acompanhamento.
- **TikTokUploader:** em prod, `API_TOKEN` do serviço =
  `TIKTOK_UPLOADER_API_TOKEN` do Laravel, `OBSERVABILITY_TOKEN` igual nos
  dois (autentica o webhook), `DRY_RUN=false` (com `true` o post vira Failed
  "DRY_RUN", nunca "postado") e `TIKTOK_UPLOADER_WEBHOOK_URL` alcançável pelo
  serviço. O uploader não devolve a URL do vídeo: post Published fica sem
  link. Risco de ban aceito.
- `php artisan config:cache` depois de mexer nas envs; nenhuma migration nova.
