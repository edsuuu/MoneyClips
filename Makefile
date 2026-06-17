x	# Makefile — atalhos para os microserviços em container (docker-compose.yml).
#
#   make micro-setup   # cria os .env faltantes a partir dos .env.example
#   make micro-up      # build + sobe os containers em background
#   make micro-ps      # status dos containers
#   make micro-logs    # logs ao vivo (Ctrl+C sai)
#   make micro-down    # derruba os containers
#   make micro-build   # rebuild das imagens sem subir
#
# generate-clips NÃO está no Docker (roda nativo no host, porta 8765).

COMPOSE := docker compose
SERVICES := download-shorts TikTokAutoUploader generate-clips

.PHONY: micro-setup micro-up micro-down micro-build micro-logs micro-ps micro-restart

## Cria os .env de cada serviço (a partir do .env.example) quando faltarem.
micro-setup:
	@for s in $(SERVICES); do \
		d="MicroServices/$$s"; \
		if [ -f "$$d/.env" ]; then \
			echo "✓ $$d/.env já existe"; \
		elif [ -f "$$d/.env.example" ]; then \
			cp "$$d/.env.example" "$$d/.env"; \
			echo "→ criado $$d/.env (revise os segredos)"; \
		else \
			echo "! $$d/.env.example não encontrado"; \
		fi; \
	done
	@mkdir -p MicroServices/TikTokAutoUploader/cookies
	@echo "Pronto. Revise os .env e rode: make micro-up"

## Build + sobe os 2 containers (download-shorts + tiktok-uploader).
micro-up:
	$(COMPOSE) up -d --build

## Rebuild das imagens (sem subir).
micro-build:
	$(COMPOSE) build

## Status dos containers.
micro-ps:
	$(COMPOSE) ps

## Logs ao vivo (Ctrl+C sai; não derruba os containers).
micro-logs:
	$(COMPOSE) logs -f

## Derruba os containers.
micro-down:
	$(COMPOSE) down

## Reinicia os containers.
micro-restart:
	$(COMPOSE) restart
