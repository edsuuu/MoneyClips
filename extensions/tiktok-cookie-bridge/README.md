# TikTok Cookie Bridge

Extensão Chrome MV3 que lê os cookies do `tiktok.com` logado neste navegador
e POSTa pro endpoint `/api/tiktok/cookies/ingest` do Laravel local, que repassa
pro tiktok-uploader (`POST /session`).

Substitui o login email/senha + captcha do uploader: você loga no TikTok
normalmente no seu Chrome, clica no ícone da extensão, ela manda os cookies.

## Instalar

1. Vá em `chrome://extensions/`.
2. Ligue o "Modo do desenvolvedor" no canto superior direito.
3. Clique em **Carregar sem compactação** e escolha esta pasta
   (`extensions/tiktok-cookie-bridge`).
4. Adicione um ícone 128×128 PNG chamado `icon.png` nesta pasta (qualquer um
   serve — sem ícone, o Chrome avisa mas continua funcionando).

## Usar

1. Faça login normalmente em https://www.tiktok.com no Chrome.
2. Abra a tela **Conectar TikTok** no Laravel (`/tiktok/connect`) e copie
   `endpoint` + `token` mostrados lá.
3. Clique no ícone da extensão, cole `endpoint` e `token`, clique
   **Ler cookies e enviar**.
4. A tela Livewire detecta sozinha (polling) e mostra "Sessão válida ✓".

O endpoint e o token ficam guardados em `chrome.storage.local` — só precisa
preencher uma vez por navegador.
