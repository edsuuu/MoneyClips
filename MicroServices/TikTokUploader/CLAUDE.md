# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> Leia primeiro o **[AGENTS.md](./AGENTS.md)** — visão geral, stack, setup,
> variáveis de ambiente, arquitetura/fluxo, autenticação, captcha e gotchas.
> Este arquivo cobre as **regras de trabalho** e o resumo de "como navegar".

## Comandos essenciais

```bash
npm start                          # sobe a API (tsx) — src/server.ts
npm run dev                        # nodemon + tsx (auto-reload ao salvar)
npm run typecheck && npm run lint  # SEMPRE antes de concluir uma tarefa
npm run lint:fix                   # corrige import/order e afins automaticamente
```

Não há build com `tsc` — o projeto roda TypeScript direto via **tsx** (esbuild).
`npm run typecheck` é só `tsc --noEmit`. Não há suíte de testes configurada.

## Como o código se encaixa (big picture)

É um **microserviço HTTP** (`src/server.ts`, porta `API_PORT`, default 8090),
orquestrado pelo **generate-clips-laravel**. **Sem banco**: o estado de cada job
volta para o Laravel via callback de webhook. Roda em **container** (Docker cuida
do restart). Camadas: `server.ts` (transporte HTTP) → `app.ts` (regras) → `services/`.

O Laravel chama `POST /posts`; o `Server` repassa para `App.createPost`
(validação zod + enfileiramento). A `UploadQueue` (em memória, **concorrência 1**
— navegador único) processa em série e costura as peças:

1. **`UploadWorkflow.upload`** monta a chave `${S3_PREFIX}{video_id}.mp4` e baixa
   o objeto do storage S3/MinIO via **`S3StorageProvider`** para uma pasta temp
   (arquivo plano, sem JSON sidecar).
2. **`TikTokAuth.ensureSession`** decide: cookies válidos → reusa; expirados →
   re-login por email/senha. **Sem QR Code** (headless): se o login falhar, lança
   `LoginFailedError`. Captcha pode aparecer **aqui**, no login — não só no upload.
3. **`TikTokUploader.upload`** abre o TikTok Studio (via `runRecordedSession`,
   gravando vídeo), valida a sessão (redirect p/ `/login` = inválida), resolve
   captcha, injeta vídeo + descrição/hashtags, e publica — **a menos que `DRY_RUN`**.
4. Ao terminar, a fila (`UploadQueue.sendCallback`) chama o `webhook_url` com o
   resultado (`completed | dry-run | failed`, `session_valid`, `login_failed`,
   `error`) — **único** retorno, nada em JSON. **Qualquer erro** vai ao Discord;
   os do navegador levam o **vídeo** da sessão anexado (e o arquivo é apagado).

Há também `GET /session` (checagem leve de expiração do cookie, sem navegador) e
`GET /health`.

Detalhe que se perde lendo um arquivo só: **a resolução de captcha
(`captcha/ResolveCaptcha.ts`) é compartilhada entre login e upload** e cobre três
tipos — ver abaixo. Seletores de UI ficam **todos** em
`src/services/tiktok/Selectors.ts`.

## Captcha — estado atual (corrigido)

Os **três** tipos são resolvidos automaticamente hoje:

- Clique "2 objetos iguais" e "selecione o objeto X" → Roboflow (`CaptchaSolver`).
- **Slider/rotação** (2 círculos + barra de arrastar) → **implementado**: compara
  os pixels das duas `img[alt="Captcha"]` num `<canvas>` para estimar o ângulo e
  arrasta `#captcha_slide_button` de forma humanizada (até 5 tentativas).

A estimativa do slider é heurística (confiança variável) — pode falhar e tentar
de novo.

## Regras de trabalho

- **Sempre** rode `npm run typecheck && npm run lint` antes de dar uma tarefa por
  pronta.
- **Nunca publique de verdade ao testar:** mantenha `DRY_RUN=true`. Publicação é
  externa e irreversível (vai para o perfil público do TikTok). Atenção: o `.env`
  deste repo pode estar com `DRY_RUN=false` (produção real) — confira antes de rodar.
- Em dry-run o navegador **fica aberto** até `Ctrl+C` (para inspeção). Isso
  **trava a fila** (concorrência 1) — dry-run serve para depurar um post, não para
  o serviço atender pedidos em produção.
- **Não commite segredos:** `.env` e `cookies/*.json` têm credenciais/sessão (já
  no `.gitignore`; a pasta `cookies/` é versionada só via `.gitkeep`).
- Automação de UI quebra quando o TikTok muda o DOM. Ajuste os seletores em
  `src/services/tiktok/Selectors.ts` e teste com `HEADLESS=false`.
- **Buffering de stdout:** ao redirecionar a saída para arquivo, o Node segura os
  logs até o processo sair — em dry-run (que não sai sozinho) os últimos logs só
  aparecem após `Ctrl+C`.

## Estilo (resumo — detalhes no AGENTS.md)

- ESM via tsx, `moduleResolution: Bundler`. Imports **sem extensão `.js`**; alias
  `@/` para cruzar pastas, `./` para irmãos.
- 4 espaços, aspas simples, ponto-e-vírgula, `printWidth` 100.
- `public`/`private` explícito em membros de classe (`explicit-member-accessibility`).
- Prefira **funções puras** (parse, geometria, normalização); **interfaces
  e tipos em `src/types/`**.
- Logue pelo `logger` (`@/config/logger/Logger`, com timestamp) — o usuário
  valoriza rastreabilidade. Comentários explicam o **porquê**, em português.
