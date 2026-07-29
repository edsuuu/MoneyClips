# MoneyClips — TUDO roda NATIVO (sem Docker): Laravel + 3 microserviços.
#   make setup   → configura o ambiente de dev uma vez (envs + deps + venv)
#   make up      → sobe tudo junto num terminal só (ctrl-C derruba tudo)
.PHONY: up setup check setup-laravel setup-media setup-tiktok setup-video

MS := MicroServices

up:  ## Sobe Laravel + microserviços nativos, todos juntos
	npx concurrently -k \
		-c "#93c5fd,#c4b5fd,#fb7185,#fdba74,#34d399,#f472b6,#facc15" \
		-n serve,queue,pail,vite,media,tiktok,video \
		"PHP_CLI_SERVER_WORKERS=8 php artisan serve" \
		"php artisan queue:listen --queue=posting,processing,default --tries=1 --timeout=1800" \
		"php artisan pail --timeout=0" \
		"npm run dev" \
		"cd $(MS)/Media && .venv/bin/python -m app.main" \
		"cd $(MS)/TikTokUploader && pnpm dev" \
		"cd $(MS)/Video && pnpm dev"

setup: setup-laravel setup-media setup-tiktok setup-video  ## Instala deps + copia .env de tudo

setup-laravel:           ## Laravel: composer + .env + key + pnpm
	composer install
	@test -f .env || echo "APP_KEY=" > .env
	php artisan key:generate
	pnpm install

setup-media:             ## Media (download YouTube + transcrição): .env + venv + pip
	@test -f $(MS)/Media/.env || cp $(MS)/Media/.env.example $(MS)/Media/.env
	cd $(MS)/Media && python3 -m venv .venv && .venv/bin/pip install -r requirements.txt

setup-tiktok:            ## TikTokUploader: .env + pnpm (baixa o Chromium do Playwright)
	@test -f $(MS)/TikTokUploader/.env || cp $(MS)/TikTokUploader/.env.example $(MS)/TikTokUploader/.env
	cd $(MS)/TikTokUploader && pnpm install

setup-video:             ## Video (HLS + reencode): .env + pnpm (precisa de ffmpeg no host)
	@test -f $(MS)/Video/.env || cp $(MS)/Video/.env.example $(MS)/Video/.env
	cd $(MS)/Video && pnpm install

check:                   ## phpstan + pint + rector + pest (o que o CI roda)
	composer check
