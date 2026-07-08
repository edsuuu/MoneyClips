# MoneyClips — Laravel roda NATIVO; Docker sobe só os microserviços.
# Alvo principal: `make up` (infra em background + composer dev em foreground).
.PHONY: up infra dev down stop logs setup check

up: infra dev            ## Sobe microserviços (bg) e o Laravel nativo (serve+queue+pail+vite)

infra:                   ## Sobe só os microserviços em background
	docker compose up -d

dev:                     ## Roda o Laravel nativo (php artisan serve + queue + pail + vite)
	composer dev

down:                    ## Derruba e remove os containers dos microserviços
	docker compose down

stop:                    ## Para os microserviços sem remover
	docker compose stop

logs:                    ## Segue os logs dos microserviços
	docker compose logs -f

setup:                   ## Instala deps + .env + key + build de assets
	composer setup

check:                   ## phpstan + pint + rector + pest (o que o CI roda)
	composer check
