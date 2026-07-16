# REFACTORING.md — contexto completo da refatoração de 07/2026

> Registro da grande refatoração entregue no
> [PR #48](https://github.com/edsuuu/MoneyClips/pull/48) (mergeado na main em
> 16/07/2026). Serve de contexto histórico para humanos e agentes: o que
> mudou, por quê, as decisões tomadas e as lições aprendidas. O estado ATUAL
> do projeto vive no [CLAUDE.md](CLAUDE.md) — em divergência, o CLAUDE.md
> vence.

## Origem

O projeto ganhou um design novo no claude.ai/design (projeto
`385d2358-997f-4157-b213-50fa4ca94947`), importado via MCP e salvo em
[`docs/designs/`](docs/designs/) (`Estoque`, `Agenda`, `Contas`,
`Observabilidade` + componentes `Slot` e `VideoCard`). A refatoração
implementou esse design e reestruturou o backend por baixo dele.

## Decisões de arquitetura (e o porquê)

1. **Só o Laravel toca o S3/MinIO.** Os microserviços de processamento e
   postagem não têm credencial de storage: o Laravel baixa o vídeo, envia o
   binário por HTTP multipart e grava o resultado de volta. Exceção
   consciente: `download-shorts` (produtor de vídeo) sobe direto pro MinIO.
   Motivo: um único dono das credenciais e do ciclo de vida dos arquivos.
2. **Agenda 100% em banco.** `schedule_slots` (data + hora + vídeo atribuído
   + `is_active` + `dispatched_at`) substituiu o mapa JSON
   `users.auto_post_schedule` + sorteio de vídeo na hora (`WindowSchedule` +
   `StockReservation`, deletados). O claim do slot é um
   `UPDATE ... WHERE dispatched_at IS NULL` — atômico, sem lock por Cache.
   Tolerância de 5 min (`GRACE_MINUTES`) protege contra tick perdido do cron.
3. **O tick do scheduler nunca posta.** Ele só reivindica o slot e enfileira
   **1 job por plataforma habilitada** (`PostSlotToPlatform`, fila `posting`,
   `tries=1` — re-tentar post de rede social às cegas arrisca duplicado; um
   timeout pode ter postado).
4. **TikTok não-oficial virou síncrono.** O microserviço `tiktok-uploader`
   tinha sido refatorado para `POST /posts` multipart síncrono, mas o Laravel
   ainda falava o contrato antigo (JSON + webhook que não existia mais). A
   integração antiga foi jogada fora (`TiktokPostService`,
   `TIkTokUploaderClient`, `TikTokPostDispatcher`,
   `TiktokPostCallbackController` + rota). O novo
   `App\Services\TikTok\TiktokUploaderClient` envia o binário + cookies do
   banco e recebe `completed|dry-run|restricted` na resposta; `401` marca a
   conta `session_status=invalid` e curto-circuita. Limitação consciente:
   sem `refreshed_cookies` — renovação de sessão é manual em `/contas`
   (evolução futura: ligar o `POST /login` do uploader).
5. **Plataformas oficiais como classes no Laravel**, não microserviços
   (API oficial é só HTTP + OAuth). `PosterRegistry` + `PosterContract`
   (`post(PostTask): PosterResult`) com stubs prontos: `TiktokOfficialPoster`
   (Content Posting API — credenciais já pré-configuradas no app TikTok for
   Developers), `InstagramReelsPoster` e `FacebookReelsPoster` (Meta Graph),
   `KwaiPoster` (Open API — validar disponibilidade BR). Toggles em
   `platform_settings` (a tela /agenda mostra os stubs como "BREVE").
6. **Observabilidade por push HTTP** (spec em [OBSERVABILITY.md](OBSERVABILITY.md),
   implementada): os 4 serviços empurram logs em lote (flush 2s/20 linhas) e
   heartbeat (30s) pros endpoints `/api/observability/*` com token
   fail-closed. Substituiu o `MicroserviceMonitor` que lia o Docker socket
   (morto desde que o docker-compose foi removido).
7. **Tudo roda nativo, sem Docker** (`make up` via concurrently; pm2/systemd
   em prod). `GenerateClips` fica fora do make por decisão.

## O que mudou por área

| Área | Antes | Depois |
| --- | --- | --- |
| Agenda | horários JSON no user + sorteio de vídeo | `schedule_slots` + kanban semanal (rascunho/salvar, picker, drag & drop, "Gerar semana", "Forçar agora", visão Mês, status por plataforma via `SlotStatus`) |
| Estoque | `/downloads` (tabela com tabs) | `/meus-videos` (grid de cards, seções Baixados/Prontos, **Editor de template**, Com template, Postados) |
| Processamento | reencode via chave S3 + fila em memória + webhook | `processing_jobs` no Laravel; Reencode multipart síncrono; AutoCaption consertado (faltava `VideoStore`) + webhook |
| TikTok | assíncrono via webhook (quebrado) | multipart síncrono no job de fila (timeout 1500s) |
| Toggles | `users.auto_post_{yt,tt}_enabled` | `platform_settings` (6 plataformas) |
| Monitoração | Docker socket (morto) | push HTTP → banco → `/observabilidade` |
| Navegação | sidebar | navbar (design), fonte única `App\View\Components\NavbarItems` |

## Banco

Tabelas novas: `schedule_slots`, `platform_settings`, `processing_jobs`,
`service_logs` (prune 14 dias), `service_heartbeats`. Alteradas:
`social_posts` (+`schedule_slot_id`, unique `(schedule_slot_id, platform)` —
1 linha por slot×plataforma é a fonte do status na /agenda), `youtube_shorts`
(+`ready_at`, `processed_video_path`, `template_rendered_at`; posters SEMPRE
usam `postableVideoPath()`), `users` (dropadas as colunas de toggle).
Migração de dados: `php artisan schedule:migrate-legacy` (one-shot,
idempotente) materializa slots a partir da agenda legada.

Detalhe técnico: `schedule_slots.slot_date` usa o cast custom
`App\Casts\DateOnly` — o `immutable_date` nativo grava `Y-m-d H:i:s`, o que
quebra comparações por data no sqlite dos testes (MySQL trunca, sqlite não).

## Contratos HTTP dos microserviços

Ver tabela no [CLAUDE.md](CLAUDE.md#microserviços-microservices--todos-nativos-sem-docker)
e detalhes no [MicroServices/README.md](MicroServices/README.md). Resumo das
mudanças: Reencode perdeu S3/fila/webhook e virou
`POST /reencode` multipart → binário `_HQ` ou JSON `skipped`; AutoCaption
ganhou `webhook_url` no `POST /videos` e o módulo `app/storage/local.py`
(`VideoStore`) que os imports exigiam e não existia.

## Qualidade de código (review por agentes)

Após a implementação, 3 agentes de review varreram blades, componentes
Livewire e services/jobs. Convenções que valem para TODO código novo (também
registradas no CLAUDE.md):

- Tela = `Route::view()` → blade wrapper (`<x-layout>` + `<livewire:...>`) →
  componente Livewire.
- **Proibido `@php` em blade**; classes condicionais SEMPRE via `@class`;
  a view recebe view-models prontos do `render()` (datas formatadas, labels,
  classes de status).
- Componentes Livewire: `mount()` primeiro, ações públicas, helpers privados,
  **`render()` por último**. (O `ordered_class_elements` do pint.json foi
  relaxado para não reordenar métodos — ele brigava com essa convenção.)
- Reuso extraído: `App\Support\Hashtags` (parse/toInput),
  `App\Jobs\Concerns\TransfersStorageFiles` (MinIO ⇄ tmp com streams
  fechados), componentes `x-ui.toggle`, `x-ui.server-modal`,
  `x-log-level-badge`, `NavbarItems` (fonte única navbar + drawer).
- Correções de review: claim atômico no webhook do AutoCaption (status
  `fetching` evita fetch duplicado em retry), dead code removido (`health()`
  ×4 sem chamadores, consts/scopes órfãos), `PlatformSetting` com 1 query
  memoizada.

## Incidentes de CI e lições aprendidas

1. **Case-sensitivity macOS × Linux**: o rename
   `TIkTokUploaderClient.php → TiktokUploaderClient.php` ficou só no
   filesystem (macOS é case-insensitive); o git manteve o case antigo e o
   PSR-4 quebrou no Linux do CI (`BindingResolutionException`). Lição:
   rename com mudança só de case exige `git mv` explícito.
2. **CI por microserviço**: além do `tests.yml` do Laravel, existem
   `download-shorts (CI)` (ruff 0.15 + mypy --strict + compileall) e
   `tiktok-uploader (CI)` (eslint/prettier + tsup build). Código Python novo
   precisa passar mypy strict (tipos genéricos completos, `datetime.UTC`,
   `contextlib.suppress`); TypeScript novo precisa passar prettier.
3. **Auto Merge quebrado**: `gh pr merge` (mutation GraphQL) devolve
   `Resource not accessible by integration (403)` com o `GITHUB_TOKEN` neste
   repo privado, mesmo com permissions write. Fix no
   [PR #49](https://github.com/edsuuu/MoneyClips/pull/49): merge via REST
   (`PUT /pulls/{n}/merge`). Ressalva permanente: PR que altera
   `.github/workflows/` nunca automergeia (exigiria escopo `workflows`).
4. **Cache de blade compilado**: trocar componente anônimo por componente de
   classe com o mesmo nome (`navbar-items`) quebra com o cache antigo —
   `php artisan view:clear` faz parte do deploy.

## Runbook de deploy desta versão

1. `php artisan migrate`
2. `php artisan schedule:migrate-legacy` (**sem isso nada posta** — o
   fallback de horários fixos foi removido)
3. `php artisan view:clear`
4. `OBSERVABILITY_TOKEN` no `.env` do Laravel + dos 4 microserviços
5. Worker: `queue:listen --queue=posting,processing,default --tries=1 --timeout=1800`
6. ⚠️ Rotacionar a chave Roboflow e o webhook Discord que estavam commitados
   no `.env.example` antigo do TikTokUploader (seguem no histórico do git)

## Limitações conscientes / próximos passos

- Posters oficiais são stubs (`failed('Poster não implementado')`) — a
  implementação real entra por plataforma, começando pelo TikTok oficial
  (credenciais já existem).
- AutoCaption exige GPU/CUDA — em macOS o serviço sobe mas o render falha
  gracioso (`processing_jobs.failed` + Discord). Validação ponta a ponta só
  na máquina com GPU.
- Editor de template não expõe "título overlay" (o AutoCaption não suporta
  `title_text`; evolução: adicionar no `template.py`).
- Renovação de sessão TikTok é manual (`/contas` ou
  `tiktok:import-cookies-from-file`); ligar o `POST /login` do uploader é a
  evolução natural.
- Tetos assumidos (comentários `ponytail:` no código): grids sem paginação
  (`SECTION_LIMIT=60`), 1 ffmpeg por vez no Reencode, worker de fila único.

## Adendo (2ª passada, pós-PR #48): estrutura + TikTok assíncrono

Feedback do dono do projeto corrigido nesta passada — o texto acima descreve
a 1ª entrega; onde divergir, vale o CLAUDE.md e o que segue:

1. **TikTok não-oficial voltou a ser ASSÍNCRONO, do jeito certo.** O item 4
   acima ("virou síncrono") foi revertido: o `POST /posts` do uploader agora
   responde `202 {job_id}` e processa numa fila serial em memória
   (`PostQueueService`); ao terminar dispara webhook pro Laravel
   (`POST /api/tiktok-posts/webhook`) com
   `{job_id, status, session_status, refreshed_cookies?}`. O poster devolve
   `queued` e grava o job_id como uuid do ledger; o
   `TiktokPostWebhookController` fecha o desfecho. Bônus recuperado do
   contrato antigo: `refreshed_cookies` renovam a sessão no banco
   automaticamente. Timeout do client caiu de 1500s para 120s.
2. **Reorganização por integração + sufixos obrigatórios**
   (`Service`/`Interface`/`Data`/`Enum`/`Job`/`Cast`). A arquitetura é
   específica de cada serviço (vive na pasta dele), sem pastas gerais tipo
   `app/Contracts`/`app/DataTransferObjects`: `PosterInterface`,
   `PostTaskData` e `PosterResultData` em `App\Services\AutoPost`;
   `TemplateStyleEnum` e `TemplateRenderOptionsData` em
   `App\Services\Processing`. Integrações por API externa em
   `App\Services\Api\{Youtube, TikTok (oficial), Meta\Instagram,
   Meta\Facebook, Kwai, Discord}`; clients de microserviço espelham
   `MicroServices/`: `App\Services\{TikTokUploader, DownloadShorts,
   Reencode, AutoCaption}`. `PostSlotToPlatform` → `PostSlotToPlatformJob`;
   `DateOnly` → `DateOnlyCast`. Código morto deletado: `PublishResult`,
   `PublishException`, `TiktokUploadResult`, `SessionInvalidException`,
   classe de constantes `AutoPost`.
3. **Zero timezone explícito**: `config/app.php` já define
   `America/Sao_Paulo` e `Date::use(CarbonImmutable)` é global — todas as
   conversões `AutoPost::TIMEZONE`/`timezone('America/Sao_Paulo')` e os
   `->timezone()` do scheduler foram removidos.
4. **Horários só do banco**: `AutoPost::DEFAULT_TIMES` morreu — o
   `WeekGeneratorService` copia a semana anterior ou a agenda legada; sem
   nada no banco, não cria slot (o operador monta a primeira semana na
   /agenda).
5. `.claude/worktrees/` blindado no `.gitignore` (worktrees do Claude Code
   nunca entram no repo).
