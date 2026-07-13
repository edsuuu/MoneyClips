# Observability — logs centralizados + heartbeat (plano futuro)

> **Status: não implementado.** Este documento descreve a arquitetura decidida
> para monitorar os microserviços quando eles rodarem **sem Docker** (pm2 /
> systemd, possivelmente em servidores separados). Hoje o
> `App\Services\MicroserviceMonitor` lê logs pelo Docker socket local — isso
> deixa de funcionar sem Docker e com serviços remotos, e será substituído
> pelo fluxo abaixo.

## Decisão

**Push HTTP dos microserviços para o próprio Laravel.** Sem serviço de
observabilidade separado, sem WebSocket, sem Loki/Grafana — overkill para 3
microserviços de projeto solo. O Laravel é o receptor central (mesmo padrão
dos webhooks que já existem), MySQL guarda tudo, e a tela `/microservices`
lê do banco com polling do Livewire.

```
tiktok-uploader (pm2, VPS B) ──┐
download-shorts (pm2, VPS C) ──┼─→ POST /api/observability/logs      (lote, ~2s)
reencode        (pm2, VPS D) ──┘   POST /api/observability/heartbeat (30s)
                                          │
                                   Laravel (VPS A)
                                   ├─ service_logs        (MySQL, prune 14 dias)
                                   ├─ service_heartbeats  (upsert, 1 row/serviço)
                                   ├─ /microservices      (query no banco + wire:poll.3s)
                                   └─ scheduler: heartbeat > 90s → Discord
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

### Tela `/microservices` (`App\Livewire\Microservices\Index`)

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

### Node (tiktok-uploader e reencode)

- `app/Services/Observability/RemoteLogger.ts` (~50 linhas): envelopa o
  logger atual — continua escrevendo no console (pm2) e empilha no buffer.
- Heartbeat: `setInterval` de 30s com `process.uptime()` e
  `process.memoryUsage().rss`.
- Env: `OBSERVABILITY_URL` (ex.: `https://dominio.com/api/observability`),
  `OBSERVABILITY_TOKEN`, `SERVICE_NAME`.
- Implementar primeiro no **tiktok-uploader** como referência; copiar para o
  reencode.

### Python (download-shorts)

- `logging.Handler` custom com a mesma lógica de buffer/flush.
- Heartbeat em background task do FastAPI (`asyncio` loop de 30s).
- Mesmas envs.

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
3. **Tela** — `/microservices` lendo do banco, badges de heartbeat, filtros;
   remover o código de Docker socket do `MicroserviceMonitor`.

## Evolução futura (se crescer)

Os serviços continuam logando estruturado no stdout, então plugar
Promtail/Alloy → Loki → Grafana depois é só configuração de infra, sem tocar
em código. WebSocket (Reverb) só se o polling de 3s incomodar.
