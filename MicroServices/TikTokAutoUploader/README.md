# TikTok Auto Uploader (TypeScript)

Microserviço HTTP que posta vídeos no TikTok a partir de um storage S3-compatível
(MinIO no local). Automação via Playwright com stealth e resolução automática de
captcha (Roboflow). Porta TypeScript do uploader Python original.

O **generate-clips-laravel** orquestra (inclusive os horários): chama a API com o
id do vídeo + uma URL de webhook; este serviço baixa o vídeo, publica no TikTok e
devolve o resultado chamando esse webhook. **Não há banco de dados nem agenda
interna** — nada é gravado em JSON.

## Como funciona

1. O Laravel chama `POST /posts` com `{video_id, webhook_url, title, hashtags?}`.
2. A API valida, enfileira (concorrência 1 — navegador único) e responde `202`.
3. Baixa `${S3_PREFIX}${video_id}.mp4` do bucket para uma pasta temporária.
4. Abre o TikTok Studio, resolve captcha se houver, preenche descrição/hashtags e
   publica (a menos que `DRY_RUN`).
5. Chama de volta o `webhook_url` com o resultado (sucesso, dry-run ou falha).

Estrutura esperada no S3 (arquivos planos do microserviço download-shorts):

```
{bucket}/shorts/{id}.mp4
```

## Setup

```bash
npm install            # instala deps + baixa o Chromium do Playwright
cp .env.example .env   # MinIO local + conta do TikTok (o .env.example já vem preenchido p/ dev)
```

## Endpoints

| Método | Rota       | O que faz                                                        |
| ------ | ---------- | ---------------------------------------------------------------- |
| GET    | `/health`  | Heartbeat (`queue_size`, `dry_run`)                              |
| GET    | `/session` | Checagem leve do cookie da conta (sem abrir navegador)           |
| POST   | `/posts`   | Enfileira um post; responde `202` e avisa no `webhook_url` ao fim |

Callback enviado ao `webhook_url` ao terminar o job:

```json
{
  "job_id": "uuid",
  "video_id": "abc",
  "status": "completed | dry-run | failed",
  "session_valid": true,
  "login_failed": false,
  "title": "...",
  "error": null,
  "finished_at": "2026-06-15T12:00:00.000Z"
}
```

## Cookies de login

Os cookies de sessão ficam em `cookies/{conta}.json`. O login automático é por
email/senha (`TIKTOK_ACCOUNT_EMAIL`/`TIKTOK_ACCOUNT_PASSWORD`). **Não há QR Code**
(o serviço é headless): se o login não concluir, o serviço avisa no Discord e no
webhook (`login_failed: true`) — faça login localmente e copie o cookie para o
servidor.

## Modos: debug (dry-run) x publicar

Controlado pela env `DRY_RUN`:

- `DRY_RUN=true` (padrão) — faz **todo** o processo mas **não publica**. Use junto
  de `HEADLESS=false` para acompanhar. O navegador fica aberto até `Ctrl+C`, o que
  trava a fila — serve para depurar, não para produção.
- `DRY_RUN=false` — publica de verdade.

## Uso

```bash
npm start              # sobe a API (tsx) — src/server.ts
npm run dev            # API com nodemon (reinicia ao salvar arquivos em src/)
```

Em produção roda em container: `docker compose up --build -d` (porta 8090,
`HEADLESS=true`; o restart fica por conta do Docker).

## Scripts

| Script              | O que faz                                |
| ------------------- | ---------------------------------------- |
| `npm start`         | Sobe a API (tsx)                         |
| `npm run api`       | Idem (alias)                             |
| `npm run dev`       | API com nodemon + tsx (auto-reload)      |
| `npm run typecheck` | Checagem de tipos (tsc --noEmit)         |
| `npm run lint`      | ESLint                                   |
| `npm run lint:fix`  | ESLint com correção automática           |
| `npm run format`    | Prettier                                 |

> Roda via `tsx` (esbuild), sem etapa de build — por isso os imports não usam
> a extensão `.js` exigida pelo ESM/NodeNext nativo.

## Estrutura

Imports usam o alias `@/` → `src/` (sem `../../`).

Arquivos em PascalCase; arquivos de tipo com sufixo `Type`.

Camadas: `server.ts` (HTTP) → `app.ts` (regras) → `services/` (infra).

```
src/
├── server.ts                     # transporte HTTP: rotas, JSON, listen
├── app.ts                        # regras: valida, monta vídeo, enfileira, sessão, health
├── UploadQueue.ts                # fila (concorrência 1) + callback webhook + Discord
├── UploadWorkflow.ts             # baixa por id -> publica no TikTok
├── auth/
│   ├── TikTokAuth.ts             # login email/senha (sem QR) + LoginFailedError
│   └── Cookies.ts                # carga, validação de expiração, save/delete, checkSession
├── config/
│   ├── env/Env.ts                # settings via zod
│   └── logger/Logger.ts          # log com cores
├── types/                        # *Type.ts (Api, Domain, Captcha, Upload, LogLevel, StorageProvider)
├── utils/                        # Sleep, Paths
└── services/
    ├── notifications/Discord.ts  # avisos no Discord (erros + vídeo)
    ├── storage/                  # S3StorageProvider (S3/MinIO via @aws-sdk)
    └── tiktok/                   # automação Playwright
        ├── Browser.ts            # contexto stealth + runRecordedSession (grava vídeo)
        ├── TikTokUploader.ts     # fluxo de upload + validação de sessão
        └── captcha/              # CaptchaSolver (Roboflow) + Geometry (funções puras)
```
