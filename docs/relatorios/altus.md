# Relatório do Altus: agenda e auto-agendamento

Dois PRs em rascunho, em sequência:

| PR | Branch | Base | O que entrega |
|---|---|---|---|
| #113 | `feat/agenda-ui` | `main` | tela `/agenda`, modal Agendar, linha de estado por plataforma |
| (seguinte) | `feat/auto-agendamento` | `feat/agenda-ui` | modo da conta (Desligada · Manual · Automática), auto-agendamento, `posts:fill`, alerta de estoque no Discord |

O rascunho da Vega (`wip: tela /agenda...`) foi revisado e reescrito num
commit limpo. Do rascunho ficaram a rota, o wrapper, o item da navbar e a
ideia de `schedule()`/`rescheduleMissed()` no `PostSchedulerService`. Saiu o
resto: `WithScheduleModal` (contas de `user_id` fixo em vez do dono do Short,
sem policy, sem lock, `firstOrCreate` que quebrava com a linha Cancelada do
unique), as blades com lógica (`collect()`, ternários e `explode()` no
template, `dark:` em `slate`, que já se inverte pelo `app.css`) e a correção
do badge que estragava o tom neutro no tema claro.

## PR 1: `feat/agenda-ui`

- **`/agenda`** (`App\Livewire\Schedule\Index`): próximos 7 dias agrupados
  por dia, uma linha por Short + horário, um selo por plataforma. Topo com
  `N agendados · cobrem D dias` (D = dias até o último agendado da conta
  ativa mais curta), próxima postagem e aviso de agenda curta (≤ 2 dias).
- **"Precisa de você"**: Failed/Missed dos últimos 7 dias. Failed mostra
  "Reconectar conta" (vai pra `/contas`) quando a causa é a conta
  (desativada, sessão inválida, token expirado sem refresh, `invalid_grant`);
  senão "Tentar de novo". Missed: "Reagendar" e, em lote, "Reagendar perdidas
  (n)" (só Missed).
- **Linha de estado** (partial `livewire/videos/partials/post-status`, mesma
  no card e na agenda): Agendado (× com confirmação) · Postando… (spinner;
  `wire:poll.10s` só enquanto houver Posting) · Postado (link + selo "Saiu
  privado") · Falhou (motivo numa linha, texto inteiro no `title`) · Perdeu o
  horário. Cancelado some e a conta volta a ser agendável.
- **Modal Agendar** (card Pronto de `/meus-videos` e linha da agenda):
  contas ativas do **dono do Short** (Short de canal usa as de quem agenda),
  as livres já marcadas, as com post ativo ou desconectadas desabilitadas com
  o motivo. "No próximo horário bom" (label por conta quando divergem) ou
  `datetime-local` (futuro, validado no servidor).
- **Serviço**: `schedule()` trava a conta (`lockForUpdate`) e reaproveita a
  linha Cancelada; `reschedule()` só vinga em Failed/Missed;
  `rescheduleMissed()` na ordem original.
- **Escopo**: listas por `SocialPost::forUser()`; `cancelPost`/`retryPost`
  checam a nova `SocialPostPolicy::update`; abrir e salvar o modal checam
  `YoutubeShortPolicy::update`, e conta de outro usuário nunca entra.
- Navbar com **Agenda**; tour de 4 passos em `/agenda` + 2 no de
  `/meus-videos` (nenhum em botão que custa); `x-ui.badge` legível no claro;
  copy de `/meus-videos` (Para revisar, Prontos para postar, Revisar título).

## PR 2: `feat/auto-agendamento`

- Migration (via `make:migration`) `auto_schedule` em `social_accounts`;
  `App\Enums\SocialAccountModeEnum` + `SocialAccount::mode()`/`applyMode()`.
  Os booleans seguem como contrato: o dispatcher continua lendo só
  `is_active`.
- `/contas`: o toggle "Ativa para publicação" virou o controle de 3 botões
  com a ajuda de cada modo (`setMode`, com policy). O resto da tela não mudou
  (o Cumulus mexe no connect do TikTok oficial).
- `markReady` agenda na hora em cada conta Automática do dono
  (`PostSchedulerService::autoSchedule`), com o toast "Vídeo marcado como
  pronto. Agendado: hoje às 14:00 no TikTok.". Short de canal não entra
  sozinho, e o automático não refaz uma linha Cancelada.
- `posts:fill` (de hora em hora) preenche o que faltou e manda
  "📉 Estoque de Shorts acabando" no Discord quando os agendados futuros de
  uma conta Automática são menos que `per_day`, no máximo 1×/dia por conta.
- `/agenda`: o aviso de agenda curta some quando há conta Automática, e o
  estado vazio sugere ligar o modo.

## Como testar

```bash
composer check   # phpstan max + pint + rector + pest
```

- PR 1: 336 testes (22 novos em `tests/Feature/Schedule/ScheduleIndexTest.php`
  e `ScheduleModalTest.php`). PR 2: 341 testes (+5 em `AutoScheduleTest.php`).
- Na mão: marque um Short como pronto em `/meus-videos`, clique em
  **Agendar** e depois veja em `/agenda`. Cancele no ×, force um Failed/Missed
  no banco e use Tentar de novo / Reagendar perdidas. No PR 2, ponha uma conta
  em Automática em `/contas`, marque outro Short como pronto e rode
  `php artisan posts:fill`.
- Validação visual feita com Playwright headless e o app em sqlite: `/agenda`,
  `/meus-videos` com o modal e `/contas`, nos temas claro e escuro, a 1280px e
  a 390px, sem rolagem lateral. A 390px, a linha de estado põe o detalhe numa
  segunda linha.

## Fora destes PRs

- Aviso "TikTok oficial sai privado" e `is_private_only` no modal: dependem
  do provider `tiktok_official` (branch do Cumulus). Quando ele entrar, falta
  um braço no `match` de `providerLabel()` em `WithPostScheduling`, e o
  PHPStan aponta o lugar.
- Thumbnail do Short (sem coluna de poster, fica o placeholder ▶) e duração
  no card (sem coluna).
- "Cookies vencem em N dias", "Por onde posta" e o connect por provider em
  `/contas`: são dos PRs de provider.
- Failed/Missed com mais de 7 dias saem do bloco de atenção (não há ação de
  descartar: o contrato limita o cancelar a Scheduled).
- O `posts:fill` agenda todos os prontos que faltam, sem horizonte máximo:
  com muito estoque, a agenda pode ir semanas à frente.
- Ambiente: PHP 8.4 tirado da imagem oficial (mirror.gcr.io) e o `phpstan`
  posto à mão no cache do composer (o proxy bloqueia o zipball do GitHub).
  Sem `ext-intl` local; a suíte não precisou.
