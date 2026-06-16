# syntax=docker/dockerfile:1

# ── builder ───────────────────────────────────────────────────────────────────
# Só instala as dependências Python. Não precisa de ffmpeg aqui (é dependência
# de runtime do yt-dlp, não da instalação). O cache mount do BuildKit mantém os
# wheels entre builds, acelerando rebuilds.
FROM python:3.11-slim AS builder

WORKDIR /app

ENV PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_ROOT_USER_ACTION=ignore

COPY requirements.txt .
RUN --mount=type=cache,target=/root/.cache/pip \
    pip install --prefix=/install -r requirements.txt

# ── runtime ───────────────────────────────────────────────────────────────────
FROM python:3.11-slim AS runtime

WORKDIR /app

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# ffmpeg é necessário em runtime pelo yt-dlp (merge de vídeo/áudio)
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Pacotes Python já compilados, vindos do builder
COPY --from=builder /install /usr/local

COPY . .

# Usuário não-root; TEMP_DIR (/tmp/download-shorts) fica sob /tmp, gravável
RUN useradd --create-home --uid 1000 appuser \
    && chown -R appuser:appuser /app
USER appuser

EXPOSE 8770

CMD ["python", "-m", "app.main"]
