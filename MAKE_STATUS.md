# Status do Makefile — nativo, sem Docker

O `make` roda tudo **nativo** (sem Docker): Laravel + DownloadShorts +
TikTokUploader + Reencode + AutoCaption. GenerateClips fica de fora de
propósito (não faz parte do fluxo atual).

## O que existe

- `make setup` — copia os `.env`, roda `composer install` + `pnpm install`,
  cria as venvs (DownloadShorts, AutoCaption) e instala as deps dos serviços.
- `make up` — sobe Laravel (`serve`/`queue`/`pail`/`vite`) + os 4 microserviços
  juntos, via `concurrently`. O worker de fila escuta
  `--queue=posting,processing,default --timeout=1800`.

## Pendências

- [ ] Pré-requisitos do host não são instalados pelo make: **ffmpeg**
      (Reencode), **MySQL** e **MinIO** externos.
- [ ] `.env` dos serviços tem valores de exemplo — revisar antes de usar
      (endpoints, tokens, webhooks; `OBSERVABILITY_TOKEN` precisa bater com o
      do Laravel).
- [x] `AutoCaption` incluído no `make` (GenerateClips fora, por decisão).
- [x] Docs `CLAUDE.md` / `README.md` / `MicroServices/README.md` atualizados —
      sem docker compose.

## Como era antes

O `docker-compose.yml` subia os microserviços em container. Foi removido; cada
serviço agora sobe individualmente pelo Makefile.
