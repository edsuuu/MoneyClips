# Microserviço headless (sem display: QR Code não funciona aqui — gere o cookie
# fora e monte o volume cookies/, ver docker-compose.yml).
#
# Multistage: o stage `deps` resolve só as dependências de produção (tsx roda o
# TS direto, então não há typescript/eslint na imagem). O stage final adiciona
# apenas o Chromium do Playwright + as libs de sistema que ele exige. O peso é
# dominado pelo Chromium (~algumas centenas de MB) — não há como fugir disso.

FROM node:22-bookworm-slim AS deps
WORKDIR /app
RUN corepack enable
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --prod --frozen-lockfile --ignore-scripts

FROM node:22-bookworm-slim
WORKDIR /app
RUN corepack enable
COPY --from=deps /app/node_modules ./node_modules

COPY package.json ./

# ffmpeg/ffprobe para o reencode (VideoReencoder). NVENC só funciona se o host
# tiver driver NVIDIA e o container rodar com --gpus all (ver docker-compose.yml);
# sem GPU o código faz fallback automático para libx264 (CPU).
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Só o Chromium + libs necessárias; limpa o cache do apt na mesma camada.
RUN pnpm exec playwright install --with-deps chromium \
    && rm -rf /var/lib/apt/lists/*

COPY tsconfig.json ./
COPY src ./src

ENV HEADLESS=true
EXPOSE 8090
CMD ["pnpm", "run", "api"]
