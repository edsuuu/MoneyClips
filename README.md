# MoneyClips

Plataforma de **auto-postagem de Shorts** em YouTube + TikTok. O Laravel
orquestra; o trabalho pesado (download e upload via navegador) roda em
microserviços dedicados em [`MicroServices/`](MicroServices/).

> Antes este repo (`generate-clips-laravel`) tinha um pipeline de cortes de
> vídeo longo — foi removido. Hoje o foco é só auto-postagem.

## Stack

- **PHP 8.4+ / Laravel 12+** (`bootstrap/app.php`)
- **Livewire 3 + Tailwind 4 + Vite** — kit próprio de componentes Blade em
  `resources/views/components/ui/` (sem Flux UI)
- **MySQL** + **fila em banco** (`QUEUE_CONNECTION=database`)
- **MinIO** (S3-compatível) — disk `s3`, bucket `videos`
- Qualidade: **PHPStan/Larastan**, **Pint**, **Rector**

## Como funciona

```
download-shorts (FastAPI, 8770) → MinIO + tabela youtube_shorts (estoque)
   → cron Laravel (schedule:run) → AutoPostDispatcher
        ├─ YoutubePoster   (síncrono, YouTube Data API v3)
        └─ TiktokPoster    (assíncrono → tiktok-uploader 8090 → webhook)
```

- **Agendamento**: 5 slots/dia (09/12/15/18/21h, fuso São Paulo), com o minuto
  sorteado por dia (muda toda semana, pra não parecer bot). Visível em `/agenda`.
- **YouTube**: OAuth Google em `social_accounts`. Toggle on/off em `/agenda`.
- **TikTok**: sem OAuth oficial — cookies do Playwright guardados
  **criptografados** em `social_accounts.cookies`. O Laravel envia os cookies
  no payload de cada post; o uploader devolve refresh + status pela webhook.
  Contas (nome, email/senha, status da sessão) ficam em `/contas`.

Detalhes de tabelas, serviços e comandos em [`CLAUDE.md`](CLAUDE.md).

## Microserviços

| Serviço | Stack | Porta | Papel |
| --- | --- | --- | --- |
| **download-shorts** | Python / FastAPI | 8770 | baixa Shorts de canais p/ o MinIO + dispara webhook por item |
| **tiktok-uploader** | Node 22 + Playwright | 8090 | publica no TikTok via navegador headless; devolve resultado por webhook |

Só os microserviços sobem via `docker compose` na raiz. O **Laravel roda nativo**
(sem Sail/container). MySQL e MinIO ficam externos no host — o Laravel nativo
acessa via `127.0.0.1`, e os containers de volta via `host.docker.internal`.

## Rodando localmente

Pré-requisitos: **Docker Desktop**, **MySQL** com o banco do `.env` criado e
**MinIO** no ar com o bucket `videos`.

```bash
composer setup       # install + env + key + build de assets (1ª vez)
make up              # docker compose up -d (microserviços) + composer dev (serve+queue+pail+vite)
```

`make up` sobe os microserviços em background e o Laravel nativo em foreground
(`http://127.0.0.1:8000`). Ctrl-C encerra o Laravel; `make down` derruba os
containers. Outros alvos: `make infra`, `make dev`, `make logs`, `make check`.

Prefere manual? `docker compose up -d --build` + `php artisan serve` +
`php artisan schedule:work` fazem o mesmo.

> **TikTok:** o container é headless. Gere os cookies fora dele e importe pro
> banco (`php artisan tiktok:import-cookies-from-file`); o status da sessão
> aparece em `/contas`. Mantenha `DRY_RUN=true` ao testar — publicação é
> irreversível.

## Produção

No servidor Linux o Laravel **roda nativo** (nginx + PHP-FPM 8.4, sem Sail); o
`docker compose` sobe só os microserviços. Os callbacks apontam pro domínio real
(nginx :80/HTTPS), não `:8000`. Cron único:

```
* * * * * cd /var/www/projects/MoneyClips && php artisan schedule:run
```

## Qualidade / CI

```bash
composer check      # phpstan + pint + rector (dry) — é o que o CI roda
composer lint       # pint + rector aplicando fixes
```
