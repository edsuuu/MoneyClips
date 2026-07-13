# MoneyClips — TUDO roda NATIVO (sem Docker): Laravel + 3 microserviços.
#   make setup   → configura o ambiente de dev uma vez (envs + deps + venv)
#   make up      → sobe tudo junto num terminal só (ctrl-C derruba tudo)
.PHONY: up setup check setup-laravel setup-download setup-tiktok setup-reencode

MS := MicroServices

up:  ## Sobe Laravel + microserviços nativos, todos juntos
	npx concurrently -k \
		-c "#93c5fd,#c4b5fd,#fb7185,#fdba74,#34d399,#f472b6,#facc15" \
		-n serve,queue,pail,vite,download,tiktok,reencode \
		"php artisan serve" \
		"php artisan queue:listen --tries=1 --timeout=0" \
		"php artisan pail --timeout=0" \
		"npm run dev" \
		"cd $(MS)/DownloadShorts && .venv/bin/python -m app.main" \
		"cd $(MS)/TikTokUploader && pnpm dev" \
		"cd $(MS)/Reencode && pnpm dev"

setup: setup-laravel setup-download setup-tiktok setup-reencode  ## Instala deps + copia .env de tudo

setup-laravel:           ## Laravel: composer + .env + key + pnpm
	composer install
	@test -f .env || cp .env.example .env
	php artisan key:generate
	pnpm install

setup-download:          ## DownloadShorts: .env + venv + pip
	@test -f $(MS)/DownloadShorts/.env || cp $(MS)/DownloadShorts/.env.example $(MS)/DownloadShorts/.env
	cd $(MS)/DownloadShorts && python3 -m venv .venv && .venv/bin/pip install -r requirements.txt

setup-tiktok:            ## TikTokUploader: .env + pnpm (baixa o Chromium do Playwright)
	@test -f $(MS)/TikTokUploader/.env || cp $(MS)/TikTokUploader/.env.example $(MS)/TikTokUploader/.env
	cd $(MS)/TikTokUploader && pnpm install

setup-reencode:          ## Reencode: .env + pnpm (precisa de ffmpeg no host)
	@test -f $(MS)/Reencode/.env || cp $(MS)/Reencode/.env.example $(MS)/Reencode/.env
	cd $(MS)/Reencode && pnpm install

check:                   ## phpstan + pint + rector + pest (o que o CI roda)
	composer check
