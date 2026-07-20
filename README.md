# MoneyClips

Plataforma de **auto-postagem de Shorts** multi-plataforma (YouTube + TikTok
hoje; TikTok oficial/Instagram/Facebook/Kwai com posters preparados). O
Laravel orquestra; o trabalho pesado (download, upload via navegador,
reencode, render de template) roda em microserviços dedicados em
[`MicroServices/`](MicroServices/). **Tudo nativo — sem Docker.**

## Stack

- **PHP 8.4+ / Laravel 13+** (`bootstrap/app.php`)
- **Livewire 4 + Tailwind 4 + Vite** — kit próprio de componentes Blade em
  `resources/views/components/ui/` (sem Flux UI)
- **MySQL** + **fila em banco** (filas `posting` e `processing`)
- **MinIO** (S3-compatível) — disk `s3`; **só o Laravel toca o S3** (os
  microserviços recebem/entregam o vídeo por HTTP multipart)
- Qualidade: **PHPStan/Larastan**, **Pint**, **Rector**
- Design das telas: [`docs/designs/`](docs/designs/) (claude.ai/design)

## Como funciona

```
download-shorts (8770) → MinIO + youtube_shorts (estoque)
   → /meus-videos: revisão → pronto (ready_at)
        └─ opcional: reencode OU template — ambos no video (8790)
   → /agenda: schedule_slots (data+hora+vídeo) → cron → AutoPostDispatcherService
        → 1 job por plataforma habilitada (platform_settings)
             ├─ YoutubePosterService  (YouTube Data API v3)
             ├─ TiktokPosterService   (202 {job_id} → tiktok-uploader 8090 → webhook)
             └─ stubs: tiktok_official, instagram, facebook, kwai
```

- **Agenda em banco**: slots concretos (data + hora + vídeo atribuído) editáveis
  em `/agenda` — kanban semanal com "Gerar semana", drag & drop, "Forçar agora"
  e status por plataforma em cada slot.
- **YouTube**: OAuth Google em `social_accounts`; connect em `/contas`.
- **TikTok**: sem OAuth oficial — cookies do Playwright **criptografados** em
  `social_accounts.cookies`. O Laravel envia o binário do vídeo + cookies por
  multipart e recebe o desfecho na resposta (`completed|dry-run|restricted`).
- **Observabilidade**: logs + heartbeat dos serviços em `/observabilidade`
  (push HTTP → banco; ver [`OBSERVABILITY.md`](OBSERVABILITY.md)).

Detalhes de tabelas, serviços e comandos em [`CLAUDE.md`](CLAUDE.md).

## Microserviços

| Serviço | Stack | Porta | Papel |
| --- | --- | --- | --- |
| **download-shorts** | Python / FastAPI | 8770 | baixa Shorts de canais p/ o MinIO + webhook por item |
| **tiktok-uploader** | Node 22 + Playwright | 8090 | publica no TikTok via navegador (assíncrono: 202 {job_id} + webhook) |
| **video** | Node 22 + ffmpeg + sharp | 8790 | todo o ffmpeg: reencode (síncrono), HLS/ABR e render de legenda karaokê + template (assíncronos + webhook) |
| **autocaption** | Python / faster-whisper (CUDA) | 8780 | só a transcrição (timestamps por palavra), chamada pelo `video` |

## Rodando localmente

Pré-requisitos: **MySQL** com o banco do `.env` criado, **MinIO** no ar com o
bucket `video`, PHP 8.4, Node 22 + pnpm, Python 3.11+, ffmpeg.

```bash
make setup     # 1ª vez: deps + .env de tudo (Laravel + 4 serviços)
php artisan migrate && php artisan schedule:migrate-legacy
make up        # sobe TUDO num terminal só (ctrl-C derruba)
```

> **TikTok:** gere os cookies fora e importe pro banco
> (`php artisan tiktok:import-cookies-from-file`); o status da sessão aparece
> em `/contas`. Mantenha `DRY_RUN=true` ao testar — publicação é irreversível.

## Produção

Laravel nativo (nginx + PHP-FPM 8.4); microserviços via pm2/systemd. Webhooks
e observabilidade apontam pro domínio real (nginx :80/HTTPS), não `:8000`.

```
* * * * * cd /var/www/projects/MoneyClips && php artisan schedule:run
```

- Worker de fila: `php artisan queue:listen --queue=posting,processing,default --tries=1 --timeout=1800`
- Deploy desta versão: rode `php artisan schedule:migrate-legacy` uma vez
  após o `migrate` e configure `OBSERVABILITY_TOKEN` em todos os `.env`.

## Qualidade / CI

```bash
composer check      # phpstan + pint + rector + pest — é o que o CI roda
composer lint       # pint + rector aplicando fixes
```
