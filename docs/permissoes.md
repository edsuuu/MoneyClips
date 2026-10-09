# Permissões e escopo

O MoneyClips vai virar um serviço (SaaS): cada usuário só enxerga e mexe no
que é dele; o admin enxerga tudo. Esta nota descreve o que existe hoje.

## Papéis (spatie/laravel-permission)

| Papel | Quem | O que pode |
| --- | --- | --- |
| `admin` | quem já existia quando os papéis entraram (migration `promote_existing_users_to_admin`, roda UMA vez) + `admin@admin.com` (`Seeder002AdminUser`) | tudo: vê todos os vídeos/shorts/contas, abre `/observabilidade` e `/log-viewer`, revisa `stock_assets` |
| `creator` | usuário novo do Google OAuth (`OAuthController::loginCallback`) | só o que tem `user_id` dele |

Os nomes vivem em `App\Enums\RoleEnum`; `User::isAdmin()` é o único ponto que
pergunta pelo papel. Usuário sem papel se comporta como creator.

### Permissões (só onde papel não basta)

`App\Enums\PermissionEnum` — todas atribuídas ao `admin` no seeder:

| Permissão | Onde é checada |
| --- | --- |
| `observability.view` | middleware `can:` da rota `/observabilidade` + item da navbar |
| `logs.view` | `Gate::define('viewLogViewer')` do pacote log-viewer (`/log-viewer`) |

Sem permissão pra `stock_assets`: a revisão é só por artisan (`assets:review`);
quando tiver tela, entra `assets.review` no enum.

A tabela `permissions` deste projeto tem a coluna extra `group` (obrigatória):
o seeder preenche com o prefixo antes do ponto. Deploy: `php artisan migrate`
(promove os existentes) e `php artisan db:seed --class=Seeder001Roles`
(permissões do admin, idempotente). A migration já cria os dois papéis, então
o login Google de usuário novo funciona antes do seed.

## Escopo por dono

Regra: **listagem filtra por scope, ação em registro único checa Policy.**

- Trait `App\Models\Concerns\BelongsToUser` (`Video`, `YoutubeShort`,
  `SocialAccount`): relação `user()` + scope `forUser(User $user)` — admin sem
  filtro, creator `where user_id`. Sem global scope.
- Policies padrão em `app/Policies/` (descobertas pelo Laravel pelo nome):
  `VideoPolicy::view`, `YoutubeShortPolicy::update`,
  `SocialAccountPolicy::update|delete`. Negativa vira 403 com a mensagem
  "Você não tem permissão para acessar este recurso." (mapeada em
  `bootstrap/app.php`).
- `Video::resolveRouteBinding` usa o scope: rota `{video:uuid}` de outro
  usuário dá **404** (HLS, legendas, multipart). As telas `Route::view`
  (`/meus-uploads/{video}`, `/editor-de-video/{cut}`) não passam pelo
  binding e checam `VideoPolicy::view` no `mount()` → **403**.
- `youtube_shorts.user_id` (nullable): o webhook do render
  (`VideoCutEditWebhookController`) grava o dono do vídeo longo ao criar o
  short; a migration faz backfill `video_cuts_edits → video_cuts →
  videos.user_id`. Short baixado de canal (`/meus-videos` → novo download) não
  tem dono e só o admin enxerga.
- `stock_assets` é global (sem dono); só admin revisa.
- `social_posts` (PR feat/posts-core): o dono vem de
  `social_accounts.user_id` — sem `user_id` próprio; o `SocialPost::forUser()`
  filtra por `whereRelation('socialAccount', 'user_id', …)`.

## Telas e componentes

| Tela | Como escopa |
| --- | --- |
| `/meus-videos` (`Videos\Index`) | listas via `forUser`; `openEdit`/`saveEdit`/`markReady` via `YoutubeShortPolicy::update`; "Novo download" (short de canal, sem dono) só admin |
| `/meus-uploads` (`Uploads\Index`) | `forUser` |
| `/meus-uploads/{video}` (`Uploads\Show`) | `VideoPolicy::view` no mount; cortes sempre via `$this->video->cuts()` |
| `/editor-de-video/{cut}` (`VideoEditor\Index`) | `VideoPolicy::view` do vídeo pai no mount |
| `/contas` (`Accounts\Index`) | lista via `forUser`; `editTiktok`/`saveTiktok`/`toggleActive` via `update`, `delete` via `delete` |
| `/observabilidade`, `/log-viewer` | só admin (permissões acima); visitante é redirecionado pro login / 403 |

`App\Livewire\Concerns\WithCurrentUser::currentUser()` devolve o `User` logado
tipado (aborta 403 sem sessão) pra alimentar o scope nos componentes.

## Fora do escopo (futuro SaaS)

Sanctum (API) e laravel-auditing ficam instalados como estão — nenhuma rota
de API foi criada. O login por senha (Fortify) também fica.
