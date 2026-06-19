# syntax=docker/dockerfile:1.7

FROM nvidia/cuda:12.6.3-cudnn-runtime-ubuntu24.04 AS runtime

ENV DEBIAN_FRONTEND=noninteractive \
    NVIDIA_DRIVER_CAPABILITIES=compute,utility,video,graphics \
    NVIDIA_VISIBLE_DEVICES=all \
    PATH=/opt/venv/bin:$PATH \
    PIP_NO_CACHE_DIR=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        ffmpeg \
        fontconfig \
        fonts-dejavu-core \
        fonts-liberation \
        libegl1 \
        libgl1 \
        libglib2.0-0 \
        libgles2 \
        libgomp1 \
        libsm6 \
        libsndfile1 \
        libxext6 \
        libxrender1 \
        python3 \
        python3-pip \
        python3-venv \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .

RUN python3 -m venv /opt/venv \
    && python -m pip install --upgrade pip setuptools wheel \
    && python -m pip install -r requirements.txt

COPY . .

RUN mkdir -p models \
    && curl -fsSL -o models/blaze_face_short_range.tflite \
        https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite \
    && curl -fsSL -o models/face_landmarker.task \
        https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task

EXPOSE 8765

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=40s \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8765/health')" || exit 1

CMD ["python", "main.py"]
