# syntax=docker/dockerfile:1
#
# OPCIONAL / posterior. O modo primário desta entrega é rodar NATIVO com venv
# (ver README). Este Dockerfile existe para quando for containerizar com GPU.
# Requer nvidia-container-toolkit no host + bloco `deploy` de GPU no compose.

FROM nvidia/cuda:12.4.1-cudnn-runtime-ubuntu22.04 AS runtime

WORKDIR /app

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-pip ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# torch CUDA primeiro (build default do PyPI é CPU-only), depois o resto.
RUN --mount=type=cache,target=/root/.cache/pip \
    pip3 install torch torchaudio --index-url https://download.pytorch.org/whl/cu124

COPY requirements.txt .
RUN --mount=type=cache,target=/root/.cache/pip \
    pip3 install -r requirements.txt

COPY . .

EXPOSE 8780

CMD ["python3", "-m", "app.main"]
