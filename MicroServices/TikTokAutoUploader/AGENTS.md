# AGENTS.md — tiktok-auto-uploader

Contexto para agentes de IA que trabalham neste repositório.

## O que é

Microserviço HTTP em TypeScript que **posta vídeos no TikTok** a partir de um
**storage S3-compatível** (MinIO local). O **generate-clips-laravel**
orquestra: chama `POST /posts` com o **id do vídeo no S3**, o título/hashtags e
uma **URL de webhook**; o serviço baixa o vídeo, publica no TikTok (Playwright +
stealth, com resolução automática de captcha via Roboflow) e **devolve o
resultado chamando esse webhook**. Não há banco de dados — o histórico fica do
lado do Laravel.

É uma reescrita em TypeScript da lib Python original (`tiktokautouploader`).

## Stack

- **Runtime:** Node ≥ 20, executado via **tsx** (esbuild) — **não há build com
  tsc**; roda o TypeScript direto.
- **Navegador:** `playwright-extra` + `puppeteer-extra-plugin-stealth`.
- **S3:** `@aws-sdk/client-s3` (S3-compat com path-style + SigV4; MinIO local).
- **Validação de env:** `zod`.
- **Captcha:** Roboflow Hosted Inference para captchas de clique; canvas no
  navegador para slider/rotação.

## Setup

```bash
npm install            # deps + baixa o Chromium do Playwright (postinstall)
cp .env.example .env   # preencher credenciais
```

## Comandos

| Comando             | O que faz                                   |
| ------------------- | ------------------------------------------- |
| `npm start`         | Sobe a API (tsx) — `src/server.ts`      |
| `npm run api`       | Idem (alias)                                |
| `npm run dev`       | API com nodemon + tsx (auto-reload)         |
| `npm run typecheck` | `tsc --noEmit`                              |
| `npm run lint`      | ESLint                                      |
| `npm run lint:fix`  | ESLint --fix                                |
| `npm run format`    | Prettier                                    |

## Variáveis de ambiente (`.env`)

- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_ENDPOINT`, `AWS_REGION`,
  `AWS_BUCKET`, `S3_PREFIX` — storage S3 (MinIO local).
- `TIKTOK_ACCOUNT_NAME` — nome lógico da conta (define `cookies/{nome}.json`).
- `TIKTOK_ACCOUNT_EMAIL`, `TIKTOK_ACCOUNT_PASSWORD` — login automático por
  email/senha. Vazios (ou se o login falhar) → avisa no Discord/webhook e pede
  cookie manual (não há QR Code: o serviço é headless).
- `ROBOFLOW_API_KEY` — captcha (tem default).
- `HEADLESS` (default `false`), `STEALTH` (default `false`).
- `DRY_RUN` (default `true`) — processa tudo **sem publicar**; mantém o navegador
  aberto até `Ctrl+C` para inspeção.
- `DISCORD_WEBHOOK_URL` — webhook Discord opcional. Recebe **qualquer erro** (com
  o vídeo da sessão quando o erro acontece no navegador).
- `API_PORT` (default `8090`) — porta própria do microserviço (diferente da do
  Laravel).

## Arquitetura

Imports usam o alias `@/` → `src/` (sem `../../`). Imports de irmão usam `./`.
Convenção: arquivos em **PascalCase**; arquivos de tipo com sufixo **`Type`**.

Camadas: **`server.ts`** (transporte HTTP) → **`app.ts`** (regras) → `services/`
(infra). O server só traduz HTTP; as regras de negócio vivem no `app`.

```
src/
├── server.ts                     # transporte HTTP: rotas, JSON, listen
├── app.ts                        # regras: valida payload, monta vídeo, enfileira, sessão, health
├── UploadQueue.ts                # fila em memória (concorrência 1) + callback webhook + Discord
├── UploadWorkflow.ts             # baixa por id -> publica no TikTok
├── auth/
│   ├── TikTokAuth.ts             # login email/senha (sem QR); exporta LoginFailedError
│   └── Cookies.ts                # carga, validação de expiração, save/delete, checkSession
├── config/
│   ├── env/Env.ts                # settings via zod
│   └── logger/Logger.ts          # log com cores + timestamp
├── types/                        # *Type.ts (Api, Domain, Captcha, Upload, LogLevel, StorageProvider)
├── utils/                        # Sleep, Paths
└── services/
    ├── notifications/Discord.ts  # funções de aviso no Discord (erros + vídeo)
    ├── storage/S3StorageProvider.ts        # S3/MinIO via @aws-sdk + % de download
    └── tiktok/
        ├── Browser.ts            # contexto stealth + runRecordedSession (grava vídeo)
        ├── TikTokUploader.ts     # fluxo de upload + validação de sessão
        └── captcha/              # CaptchaSolver (Roboflow) + Geometry (funções puras)
```

### Fluxo (`POST /posts` → `App.createPost` → `UploadWorkflow.upload`)

1. `Server` recebe o POST e chama `App.createPost`, que valida (zod), enfileira e
   devolve `202 {job_id, status: "queued"}` (payload inválido → 422).
2. A fila (concorrência 1, navegador único) processa em série: monta a chave
   `${S3_PREFIX}${video_id}.mp4` e baixa o objeto para uma pasta temporária.
3. `TikTokUploader.upload`: garante sessão → abre upload (gravando vídeo) →
   resolve captcha → injeta vídeo → descrição/hashtags → publica (ou para, se
   `DRY_RUN`).
4. Ao terminar, a fila chama o `webhook_url` com o resultado. Sucesso volta só
   pelo webhook; qualquer erro também vai ao Discord (com o vídeo da sessão).

### API (microserviço orquestrado pelo Laravel)

`npm start` sobe o servidor HTTP (`src/server.ts`, porta `API_PORT`,
default 8090). Sem banco — o histórico fica do lado do Laravel.

- `GET /health` — heartbeat (inclui `queue_size` e `dry_run`).
- `GET /session` — checagem **leve** do cookie da conta (sem abrir o navegador):
  `{account, has_cookies, expired, valid}`.
- `POST /posts` — `{video_id, webhook_url, title, hashtags?}`: baixa
  `shorts/{video_id}.mp4` do S3 e publica. Responde `202` na hora.

**Callback do webhook** (POST no `webhook_url` ao fim do job):

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

Falha de login automático → `status: "failed"`, `login_failed: true` (e aviso no
Discord). A fila é em memória; reiniciar o processo perde jobs ainda na fila.

Docker: `docker compose up --build -d` (porta 8090, `HEADLESS=true` forçado).
O login automático é por email/senha; sem ele, gere os cookies fora do container
(login local com `HEADLESS=false`) — o volume `cookies/` leva a sessão.

### Notificações e gravação

- **Webhook**: `UploadQueue.sendCallback` faz o `POST` de volta ao `webhook_url`
  de cada job ao terminar. É o **único** retorno de sucesso/falha (nada em JSON).
- **Discord** (`services/notifications/Discord.ts`, funções, sem classe): recebe
  **qualquer erro** nos catches. Os da sessão do navegador vêm com o **vídeo**
  gravado anexado; os demais (ex.: download do S3), só texto. `wasDiscordReported`
  evita aviso duplicado quando o erro sobe da sessão até a fila.
- **Vídeo**: `runRecordedSession` (em `Browser.ts`) grava a sessão; em erro,
  finaliza o `.webm`, envia ao Discord e apaga. Em sucesso, descarta. Vídeo acima
  do limite do Discord cai para aviso só-texto.

### Autenticação (`auth/TikTokAuth`)

`ensureSession(conta)`:
1. Cookies em `cookies/{conta}.json` e **não expirados** → reutiliza.
2. Expirados → apaga e tenta login novo.
3. Login novo: email/senha em `/login/phone-or-email/email`. **Sem fallback de QR
   Code** (serviço headless): se não concluir, avisa no Discord e lança
   `LoginFailedError` (a fila reporta `login_failed: true` no webhook). Resolva
   fazendo login localmente e copiando o cookie para o servidor.

Estrutura esperada no S3 (arquivos planos do microserviço download-shorts):

```
{bucket}/shorts/{id}.mp4
```

## Captcha — o que é suportado

Resolvidos automaticamente:

- "Select 2 objects that are the same" — Roboflow, modelo de detecção para clique.
- "Select the object …" — Roboflow, pergunta mapeada para classe de objeto.
- Slider/rotação ("2 círculos + barra para arrastar") — sem Roboflow: usa
  `canvas` dentro do navegador para comparar pixels das duas imagens
  `img[alt="Captcha"]`, estima o ângulo e arrasta `#captcha_slide_button`.

Detalhes do slider/rotação:

- Container: `#captcha-verify-container-main-page`.
- Texto comum: "Arraste o controle deslizante para encaixar o quebra-cabeças".
- Imagens: duas `img[alt="Captcha"]`; a maior é o anel e a menor é o disco.
- Botão: `#captcha_slide_button`.
- Refresh: `#captcha_refresh_button`.
- Trilha do slider: ancestral do botão com classe `cap-h-40`.
- A lógica fica em `src/services/tiktok/captcha/ResolveCaptcha.ts` e tenta o
  captcha de rotação até 5 vezes antes de falhar.

## Convenções de código

- **ESM via tsx**, `moduleResolution: Bundler`. **Imports sem extensão `.js`.**
- Alias `@/` para cruzar pastas; `./` para irmãos.
- Indentação 4 espaços, aspas simples, ponto-e-vírgula, `printWidth` 100.
- `explicit-member-accessibility` obrigatório (`public`/`private` em tudo).
- **Funções puras** sempre que possível (parse, geometria, normalização).
- **Interfaces e tipos vivem em `src/types/`.**
- Logs sempre pelo `logger` (com timestamp) — facilita rastreabilidade.
- Comentários explicam o **porquê** (em português), não o óbvio.

## Gotchas

- **S3-compat (MinIO/Contabo):** exige `forcePathStyle: true`, `signature v4`, `region us-east-1`.
  O endpoint é lento — timeouts generosos no client.
- **Buffering de stdout:** quando a saída vai para arquivo (não TTY), o Node
  segura os logs até o processo sair. Em `DRY_RUN` o processo não sai sozinho, então
  ao depurar via arquivo de log os últimos logs só aparecem após `Ctrl+C`.
- **Seletores frágeis:** a UI do TikTok muda. Tudo centralizado em
  `services/tiktok/Selectors.ts` — ajuste lá primeiro, rodando com `HEADLESS=false`.
- **Cookies são segredo:** `cookies/*.json` contêm a sessão — nunca commitar
  (já no `.gitignore`).

## Antes de concluir qualquer mudança

Rode **sempre**:

```bash
npm run typecheck && npm run lint
```

Não publique de verdade em testes: use `DRY_RUN=true` (padrão).
