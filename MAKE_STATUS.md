# Status do Makefile — ⚠️ NÃO totalmente configurado

O `make` foi migrado pra rodar tudo **nativo** (sem Docker), mas ainda **não
está 100% configurado/validado**. Use com cuidado no primeiro momento.

## O que já existe

- `make setup` — copia os `.env`, roda `composer install` + `pnpm install`,
  cria a venv do DownloadShorts e instala as deps dos microserviços Node.
- `make up` — sobe Laravel (`serve`/`queue`/`pail`/`vite`) + DownloadShorts +
  TikTokUploader + Reencode juntos, via `concurrently`.

## Pendências / não validado

- [ ] `make setup` e `make up` ainda **não foram executados de ponta a ponta** —
      podem quebrar na 1ª rodada.
- [ ] Pré-requisitos do host não são instalados pelo make: **ffmpeg** (Reencode),
      **MySQL** e **MinIO** externos (o compose que os subia foi removido).
- [ ] `.env` dos serviços tem valores de exemplo — revisar antes de usar
      (endpoints, credenciais, webhooks).
- [ ] `AutoCaption` e `GenerateClips` **não** estão no `make` (não estavam no
      compose antigo).
- [ ] Docs `CLAUDE.md` / `README.md` ainda citam `docker compose` na seção
      "Rodar tudo" — desatualizados.

## Como era antes

O `docker-compose.yml` subia os microserviços em container. Foi removido; cada
serviço agora sobe individualmente pelo Makefile.
