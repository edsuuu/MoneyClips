# generate-clips-laravel

Plataforma unificada de **geração de clipes** e **auto-postagem em redes sociais**.
O Laravel orquestra; o trabalho pesado (download, transcrição, render, upload)
roda em microserviços dedicados — agora centralizados neste repositório, em
[`MicroServices/`](MicroServices/).

## Stack

- **PHP 8.4+ / Laravel 12+** (bootstrap em `bootstrap/app.php`)
- **Livewire 3 + Tailwind 4 + Vite** — **sem Flux UI**: kit próprio de componentes
  Blade em `resources/views/components/ui/` (button, input, badge, modal,
  dropdown, icon, toasts...) com Alpine.
- **MySQL** (`DB_CONNECTION=mysql`) + **fila em banco** (`QUEUE_CONNECTION=database`)
- **MinIO** (S3-compatível) para vídeos — disk `minio`
- Qualidade: **Pest** (testes), **PHPStan/Larastan**, **Pint**, **Rector**

## Dependências externas (microserviços)

Esta aplicação **não funciona sozinha** — depende de 3 microserviços, hoje
versionados em [`MicroServices/`](MicroServices/) (ver
[MicroServices/README.md](MicroServices/README.md)):

| Serviço | Stack | Porta | Como roda | Papel | Acessa |
| --- | --- | --- | --- | --- | --- |
| **download-shorts** | Python / FastAPI | 8770 | Docker (compose) | baixa Shorts de canais p/ o storage e cria jobs de download | MySQL `download_shorts`, S3/MinIO |
| **TikTokAutoUploader** | Node 22 + Playwright | 8090 | Docker (compose) | publica vídeos no TikTok via navegador; devolve o resultado por webhook | S3/MinIO, TikTok (web), Discord |
| **generate-clips** (video processor) | Python / FastAPI | 8765 | **Nativo no host** | download/transcrição/render dos cortes; responde via webhook | MinIO, LLMs, Whisper, ffmpeg |

> **Por que generate-clips fica fora do Docker?** Usa GPU (Whisper MLX/Metal e
> ffmpeg videotoolbox no macOS), indisponível em container no Docker Desktop.
> Roda nativo no host — instruções em [MicroServices/README.md](MicroServices/README.md).

Pré-requisitos de infra no host (reaproveitados pelos containers via
`host.docker.internal`): **MySQL** (com o banco `download_shorts`), **MinIO**
(bucket de vídeos) e **Docker Desktop**.

## Os 3 domínios da aplicação

1. **Pipeline de vídeo/cortes** — usuário cola a URL em `/videos/create` →
   `ProcessVideoJob` chama a API Python (**generate-clips**, porta 8765) →
   o Python salva no MinIO e responde via webhook
   (`POST /api/video-processor/callbacks`). Progresso em tempo real via WebSocket.
2. **Publicação social** — cortes → redes (YouTube/Instagram/Facebook). Contas
   OAuth em `social_accounts`; agendamento em `scheduled_posts`
   (`social:publish-due`, scheduler). Dashboard em `/posts`.
3. **Auto-postagem de Shorts** — baixa Shorts (**download-shorts**, 8770) → estoque
   no MinIO → sorteia e posta no YouTube/TikTok (**TikTokAutoUploader**, 8090).
   Página `/shorts`.

(Detalhes de tabelas, serviços e comandos artisan em `CLAUDE.md`.)

## Rodando localmente

### 1. Laravel
```bash
composer setup                         # install + env + key + pnpm + build
php artisan serve --host=0.0.0.0       # 0.0.0.0 p/ os containers alcançarem os callbacks
composer dev                           # queue:listen + pail + vite (em paralelo)
php artisan schedule:work              # agendamentos (publicações + shorts)
```

### 2. Microserviços
```bash
make micro-setup     # cria os MicroServices/*/.env a partir dos .env.example
# revise os .env (segredos, contas, DRY_RUN)
make micro-up        # build + sobe download-shorts (8770) e tiktok-uploader (8090)
make micro-ps        # status   |   make micro-logs (logs)   |   make micro-down (parar)
```
E o generate-clips nativo:
```bash
cd MicroServices/generate-clips && python main.py   # API em 127.0.0.1:8765
```

> **TikTok:** o container é headless (sem QR Code). Gere os cookies de sessão
> fora dele (`HEADLESS=false`) — são carregados pelo volume `cookies/`. Mantenha
> `DRY_RUN=true` ao testar; publicação é irreversível.

## Qualidade / CI

```bash
composer check      # phpstan + pint + rector (dry) + pest — é o que o CI roda
composer lint       # pint + rector aplicando fixes
php artisan test    # suíte Pest
```
