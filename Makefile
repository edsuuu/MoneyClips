# MoneyClips — TUDO roda NATIVO (sem Docker): Laravel + 4 microserviços.
# (GenerateClips fica de fora de propósito — não faz parte do fluxo atual.)
#   make setup   → configura o ambiente de dev uma vez (envs + deps + venv)
#   make up      → sobe tudo junto num terminal só (ctrl-C derruba tudo)
.PHONY: up setup check setup-laravel setup-download setup-tiktok setup-video setup-transcriber

MS := MicroServices

up:  ## Sobe Laravel + microserviços nativos, todos juntos
	npx concurrently -k \
		-c "#93c5fd,#c4b5fd,#fb7185,#fdba74,#34d399,#f472b6,#facc15,#a3e635" \
		-n serve,queue,pail,vite,download,tiktok,video,transcriber \
		"PHP_CLI_SERVER_WORKERS=8 php artisan serve" \
		"php artisan queue:listen --queue=posting,processing,default --tries=1 --timeout=1800" \
		"php artisan pail --timeout=0" \
		"npm run dev" \
		"cd $(MS)/DownloadYoutube && .venv/bin/python -m app.main" \
		"cd $(MS)/TikTokUploader && pnpm dev" \
		"cd $(MS)/Video && pnpm dev" \
		"cd $(MS)/Transcriber && .venv/bin/python -m app.main"

setup: setup-laravel setup-download setup-tiktok setup-video setup-transcriber  ## Instala deps + copia .env de tudo

setup-laravel:           ## Laravel: composer + .env + key + pnpm
	composer install
	@test -f .env || echo "APP_KEY=" > .env
	php artisan key:generate
	pnpm install

setup-download:          ## DownloadYoutube: .env + venv + pip
	@test -f $(MS)/DownloadYoutube/.env || cp $(MS)/DownloadYoutube/.env.example $(MS)/DownloadYoutube/.env
	cd $(MS)/DownloadYoutube && python3 -m venv .venv && .venv/bin/pip install -r requirements.txt

setup-tiktok:            ## TikTokUploader: .env + pnpm (baixa o Chromium do Playwright)
	@test -f $(MS)/TikTokUploader/.env || cp $(MS)/TikTokUploader/.env.example $(MS)/TikTokUploader/.env
	cd $(MS)/TikTokUploader && pnpm install

setup-video:             ## Video (HLS + reencode): .env + pnpm (precisa de ffmpeg no host)
	@test -f $(MS)/Video/.env || cp $(MS)/Video/.env.example $(MS)/Video/.env
	cd $(MS)/Video && pnpm install

setup-transcriber:       ## Transcriber: .env + venv + pip (GPU/CUDA pra transcrição)
	@test -f $(MS)/Transcriber/.env || cp $(MS)/Transcriber/.env.example $(MS)/Transcriber/.env
	cd $(MS)/Transcriber && python3 -m venv .venv && .venv/bin/pip install -r requirements.txt

check:                   ## phpstan + pint + rector + pest (o que o CI roda)
	composer check
