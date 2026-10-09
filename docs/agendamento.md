# Agendamento e postagem automática

Estado: **núcleo pronto, sem provider real** (PR `feat/posts-core`). Só
providers nativos, escolhidos por conta, cada um no seu PR e nesta ordem:
YouTube Data API (`videos.insert`, OAuth que já existe em `/contas`) →
TikTokUploader (cookies) → TikTok Content Posting API oficial (direct post,
privado até a auditoria passar). Nenhuma agregadora. A tela `/agenda` e o
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

1. Classe em `app/Services/Posting/` implementando `PostProviderInterface`
   (`post(SocialPost $post, string $localPath): PostResultData`). A legenda é
   `$post->youtubeShort->caption()` (título + hashtags sem duplicar), lida na
   hora do post.
2. Devolver `PostResultData::published($url, 'public'|'private')`,
   `::pending($externalId)` (o webhook fecha achando por `external_id`) ou
   `::failed($motivo)`.
3. Case no `PostProviderEnum` e o braço no `match` de `service()`.

Hoje `service()` resolve `PostProviderInterface` do container, que só os
testes ligam (`Tests\Fakes\FakePostService`): em produção, sem provider, o job
falha com o motivo.

## Operação

- Prod: o cron `* * * * * php artisan schedule:run` roda o `posts:dispatch`.
  Em dev, `php artisan schedule:work` (o `make up` não sobe o agendador).
- Worker próprio da fila `posting` (`make up` sobe os dois), pra um upload
  longo não travar a fila `processing`.
- Logs em `daily` com `[INFO|WARN|ERRO][Posting]`; Discord em Missed, Failed e
  Published.
- Missed por servidor desligado: reagendar é do dono na tela `/agenda`
  (botão por post ou "Reagendar perdidas", que reagenda todos os Missed nos
  próximos horários bons, na ordem original; Failed fica de fora: o dono lê
  o motivo antes).

## Tela `/agenda` e modal Agendar

- `/agenda` (`App\Livewire\Schedule\Index`): próximos 7 dias por dia, uma
  linha por Short + horário com um selo por plataforma; bloco "precisa de
  você" com Failed/Missed dos últimos 7 dias. Failed mostra "Reconectar
  conta" quando a causa é a conta (desativada, sessão inválida, token sem
  refresh, `invalid_grant`) e "Tentar de novo" no resto.
- Modal Agendar (card Pronto de `/meus-videos` e linha da agenda): 1
  checkbox por conta ativa do dono do Short, já marcadas as livres, +
  "No próximo horário bom" ou `datetime-local`. Conta com post ativo daquele
  Short aparece desabilitada com o motivo; cancelado volta a ser agendável
  (a linha do unique é reaproveitada).
- Escopo: listas via `SocialPost::forUser()`; cancelar/tentar de novo via
  `SocialPostPolicy::update` (admin ou dono da conta).

## Modo da conta e auto-agendamento

- Um controle só em `/contas`: **Desligada** (`is_active=false`, some do
  modal) · **Manual** (ativa; o dono agenda) · **Automática** (ativa +
  `auto_schedule`). `SocialAccountModeEnum` + `SocialAccount::mode()` /
  `applyMode()`; o dispatcher segue lendo só `is_active`.
- Short marcado como pronto (`markReady`) entra na hora no próximo horário
  livre de cada conta Automática do dono (`PostSchedulerService::autoSchedule`).
  Short de canal (sem dono) nunca entra sozinho; conta que já tem qualquer
  linha daquele Short (inclusive Cancelada) fica de fora: cancelar é
  definitivo pro automático.
- `posts:fill` (de hora em hora) preenche o que faltou, do pronto mais antigo
  pro mais novo (conta que virou Automática depois, por exemplo), e avisa no
  Discord (`services.discord.webhook`) quando os agendados futuros de uma
  conta Automática são menos que `per_day` — no máximo 1 aviso por conta por
  dia (cache `posting:low-stock:{id}`).
