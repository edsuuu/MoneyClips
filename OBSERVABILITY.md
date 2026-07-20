# Observability — logs centralizados + heartbeat

> **Status: IMPLEMENTADO.** Endpoints `/api/observability/{logs,heartbeat}`
> (middleware `VerifyObservabilityToken`), tabelas `service_logs` /
> `service_heartbeats`, comando `observability:check-heartbeats`, tela
> `/observabilidade` e observers nos 4 microserviços
> (`RemoteObservability.ts` no tiktok-uploader/video, `observability.py`
> no download-shorts/transcriber). O antigo `MicroserviceMonitor` (Docker
> socket) foi removido. Este documento segue como referência da arquitetura.

## Decisão

**Push HTTP dos microserviços para o próprio Laravel.** Sem serviço de
observabilidade separado, sem WebSocket, sem Loki/Grafana — overkill para 3
microserviços de projeto solo. O Laravel é o receptor central (mesmo padrão
dos webhooks que já existem), MySQL guarda tudo, e a tela `/observabilidade`
lê do banco com polling do Livewire.

```
tiktok-uploader (pm2, VPS B) ──┐
download-shorts (pm2, VPS C) ──┼─→ POST /api/observability/logs      (lote, ~2s)
video           (pm2, VPS D) ──┤   POST /api/observability/heartbeat (30s)
transcriber     (pm2, VPS E) ──┘
                                          │
                                   Laravel (VPS A)
                                   ├─ service_logs        (MySQL, prune 14 dias)
                                   ├─ service_heartbeats  (upsert, 1 row/serviço)
                                   ├─ /observabilidade    (query no banco + wire:poll.3s)
                                   └─ scheduler: heartbeat > 90s → Discord

⚠️ `service_heartbeats` é upsert por NOME de serviço e o
`observability:check-heartbeats` varre a tabela inteira. Renomear um serviço
deixa a linha antiga órfã, que passa a alertar "fora do ar" pra sempre — apague
a linha junto com o rename (foi o que a migration
`delete_renamed_service_heartbeats` fez com `hls`/`reencode`/`autocaption`).
```

Racional:

- **Ler logs do PM2 remotamente está descartado** — exigiria SSH do Laravel
  para cada VPS ou expor o pm2 na rede.
- **Push é o padrão da casa** — os 3 microserviços já fazem webhook pro
  Laravel; logs e heartbeat são só mais dois endpoints.
- **PM2 continua intocado** — o logger novo escreve no console *e* envia pro
  Laravel; `pm2 logs` local segue funcionando para debug.
- **Sem WebSocket** — polling de 3s numa tabela indexada é indistinguível de
  tempo real para um painel admin. Se um dia incomodar, troca-se por Laravel
  Reverb sem mexer nos microserviços.
- **Heartbeat > logs** — saber que um serviço caiu (Discord) vale mais que o
  painel. É 20% do esforço e 80% do valor; implementar primeiro.

## Lado Laravel

### Endpoints (`routes/api.php`)

Autenticação: token compartilhado no header `X-Observability-Token`,
comparado com `config('services.observability.token')` (env
`OBSERVABILITY_TOKEN`) via middleware dedicado. Os webhooks atuais não têm
auth — os endpoints novos já nascem com token, e vale estender aos webhooks
depois.

- `POST /api/observability/logs` — recebe lote:

  ```json
  {
    "service": "tiktok-uploader",
    "hostname": "vps-b",
    "entries": [
      { "level": "info", "message": "[1/7] Abrindo navegador...", "context": null, "logged_at": "2026-07-12T19:04:08-03:00" }
    ]
  }
  ```

  Insere em `service_logs` (um `insert` em lote, não um por linha).

- `POST /api/observability/heartbeat` — recebe:

  ```json
  {
    "service": "tiktok-uploader",
    "hostname": "vps-b",
    "version": "1.4.2",
    "uptime_seconds": 12452,
    "memory_mb": 245
  }
  ```

  `upsert` em `service_heartbeats` pela chave `service`.

### Migrations

- `service_logs`: `id`, `service` (string, index composto com `created_at`),
  `hostname`, `level` (string curto: debug/info/warn/error), `message`
  (text), `context` (json nullable), `logged_at` (timestamp do lado do
  serviço), `created_at`.
- `service_heartbeats`: `service` (unique), `hostname`, `version` (nullable),
  `uptime_seconds`, `memory_mb` (nullable), `last_seen_at`, timestamps.

### Scheduler (`routes/console.php`)

- `observability:check-heartbeats` — a cada minuto. Heartbeat com
  `last_seen_at` > 90s → Discord error via `DiscordNotifier`, com dedupe
  `Cache::add` de 1×/queda (mesmo padrão do `auto-post:check-missed`).
  Quando o serviço volta, avisar recuperação e limpar o dedupe.
- Prune diário de `service_logs` com mais de 14 dias
  (`Model::prune` ou delete no scheduler).

### Tela `/observabilidade` (`App\Livewire\Observability\Index`)

- Fonte dos logs passa a ser query em `service_logs` (substitui
  `MicroserviceMonitor::logs()` / Docker Engine API — todo o código de
  socket/decode de stream some).
- Health: manter o ping em `GET /health` de cada serviço (funciona remoto
  igual) **+** badge de heartbeat (`last_seen_at`, uptime, memória, versão).
- `wire:poll.3s` na área de logs (a tela já tem auto-refresh; ajustar para o
  banco).
- Filtros: por serviço e por level; busca simples em `message` é bônus.

## Lado microserviços

Regra de ouro do logger remoto: **fire-and-forget**. Buffer em memória,
flush a cada 2s ou 20 linhas (o que vier primeiro), timeout curto, e se o
Laravel estiver fora do ar, descarta e segue — o envio de log **nunca** pode
derrubar ou atrasar o serviço.

### Node (tiktok-uploader e video)

- `app/Services/RemoteObservability.ts`: registra um sink no logger — o
  console (pm2) continua sendo a saída primária e as linhas também vão pro
  buffer.
- No `video`, `Logger` é uma classe abstrata que as demais estendem, e os
  sinks são **estáticos**: a observabilidade registra um só e ele vale pra
  todas as subclasses. Sink de instância capturaria só o log de quem
  registrou.
- Heartbeat: `setInterval` de 30s com `process.uptime()` e
  `process.memoryUsage().rss`.
- Env: `OBSERVABILITY_URL` (ex.: `https://dominio.com/api/observability`),
  `OBSERVABILITY_TOKEN`, `SERVICE_NAME`.

### Python (download-shorts e transcriber)

- `app/observability.py`: `RemoteLogHandler(logging.Handler)` com a mesma
  lógica de buffer/flush, plugado no logger por `start_observability()`. Aqui
  a junção com o log local sai de graça — `logging.Handler` já é a abstração
  que no TypeScript teve que ser escrita à mão.
- Heartbeat: thread daemon com loop de 30s.
- Mesmas envs. Os dois arquivos são cópias quase idênticas entre os dois
  serviços Python — ao mexer num, replique no outro.

## Rede

- Microserviço → Laravel: em dev, `http://host.docker.internal:8000` deixa de
  ser necessário (tudo nativo) — usar `http://127.0.0.1:8000`. Em prod, o
  domínio real via nginx/HTTPS.
- Laravel → microserviço (`/health`): URL de cada serviço já vem de
  `config/services.php`; com servidores separados vira o IP/domínio da VPS.

## Ordem de implementação

1. **Heartbeat** — migration `service_heartbeats`, endpoint, middleware de
   token, `setInterval` nos 3 serviços, comando `observability:check-heartbeats`
   + Discord. (Maior valor, menor esforço.)
2. **Pipeline de logs** — migration `service_logs`, endpoint de lote,
   `RemoteLogger` no uploader → replicar nos outros, prune.
3. **Tela** — `/observabilidade` lendo do banco, badges de heartbeat, filtros;
   remover o código de Docker socket do `MicroserviceMonitor`.

## Evolução futura (se crescer)

Os serviços continuam logando estruturado no stdout, então plugar
Promtail/Alloy → Loki → Grafana depois é só configuração de infra, sem tocar
em código. WebSocket (Reverb) só se o polling de 3s incomodar.
