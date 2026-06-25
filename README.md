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
  Cole cookies novos em `/settings/accounts`.

Detalhes de tabelas, serviços e comandos em [`CLAUDE.md`](CLAUDE.md).

## Microserviços

| Serviço | Stack | Porta | Papel |
| --- | --- | --- | --- |
| **download-shorts** | Python / FastAPI | 8770 | baixa Shorts de canais p/ o MinIO + dispara webhook por item |
| **tiktok-uploader** | Node 22 + Playwright | 8090 | publica no TikTok via navegador headless; devolve resultado por webhook |

Ambos sobem via `docker compose` na raiz; MySQL e MinIO ficam externos no host
(acessados via `host.docker.internal`).

## Rodando localmente

Pré-requisitos: **Docker Desktop**, **MySQL** com o banco do `.env` criado e
**MinIO** no ar com o bucket `videos`.

```bash
composer setup                                # install + env + key + build de assets
docker compose up -d --build                  # sobe download-shorts + tiktok-uploader
php artisan serve                             # Laravel nativo em 127.0.0.1:8000
php artisan schedule:work                     # agendamento de auto-postagem
```

> **TikTok:** o container é headless. Gere os cookies fora dele e cole o JSON em
> `/settings/accounts`. Mantenha `DRY_RUN=true` ao testar — publicação é irreversível.

## Produção

No servidor Linux o Laravel **roda nativo** (nginx + PHP-FPM 8.4, sem Sail). Um
`docker-compose.override.yml` desabilita o serviço `laravel` do compose pra
evitar conflito na porta 80. Cron único:

```
* * * * * cd /var/www/projects/MoneyClips && php artisan schedule:run
```

## Qualidade / CI

```bash
composer check      # phpstan + pint + rector (dry) — é o que o CI roda
composer lint       # pint + rector aplicando fixes
```
