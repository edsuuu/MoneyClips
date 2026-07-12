# Microserviço de reencode: baixa do S3, recodifica com ffmpeg e devolve ao S3.
#
# Multistage: o stage `deps` resolve só as dependências de produção (tsx roda o
# TS direto, então não há typescript/eslint na imagem). O stage final adiciona
# apenas o ffmpeg. NVENC (GPU NVIDIA) só funciona se o host tiver driver NVIDIA e
# o container rodar com --gpus all (ver docker-compose.yml); sem GPU o código faz
# fallback automático para libx264 (CPU).

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

# ffmpeg/ffprobe para o reencode (VideoReencoder).
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

COPY tsconfig.json ./
COPY src ./src

EXPOSE 8790
CMD ["pnpm", "run", "api"]
