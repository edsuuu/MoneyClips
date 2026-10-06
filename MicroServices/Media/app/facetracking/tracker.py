"""Face tracking + active speaker detection (MediaPipe Tasks API).

Fluxo: amostra o vídeo a N fps, o FaceLandmarker devolve os landmarks de cada
rosto (a bbox sai dos próprios landmarks — sem um segundo modelo detector, sem
o risco de casar a bbox de uma pessoa com os landmarks de outra), a identidade
de cada rosto é mantida por IoU entre frames, e o "quem está falando" sai da
atividade labial da pessoa multiplicada pela energia do áudio naquele instante.
A saída é a trajetória do recorte 9:16 já simplificada por Ramer-Douglas-Peucker.

`style="cuts"` (porte do render.py do protótipo) troca a câmera suave por
tomadas fixas: o YuNet acha rosto de qualquer tamanho, o recorte de cada rosto
vai ampliado pro FaceLandmarker medir a boca, a troca é seca quando o locutor
muda por >=0.5s ou há corte de cena, e plano aberto ganha zoom até o rosto
ocupar FACE_TARGET da largura do recorte. A identidade de cada pessoa vem da
aparência (SFace), não só do IoU: corte de câmera e rosto que some e volta não
viram "pessoa nova".

Só stdlib + numpy no topo do módulo: cv2/mediapipe entram lazy dentro de
`track()`, então as funções puras daqui (IoU, região, RDP) são importáveis e
testáveis sem nenhum dos dois instalados.
"""

from __future__ import annotations

import itertools
import logging
import os
import platform
import re
import subprocess
from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import numpy as np
import numpy.typing as npt

logger = logging.getLogger("media.facetracking.tracker")

MODEL_FILENAME = "face_landmarker.task"
MODEL_URL = (
    "https://storage.googleapis.com/mediapipe-models/face_landmarker/"
    "face_landmarker/float16/1/face_landmarker.task"
)

# ponytail: o detector embutido no FaceLandmarker é short-range (entrada 128x128),
# então num frame 1080p ele só enxerga rosto com ~14% ou mais da largura (medido:
# 265px em 1920 detecta, 230px não). Talking head típico passa; plano aberto não —
# nesse caso o clipe sai com crop centralizado. Upgrade: detectar em tiles
# sobrepostos, ou trocar por um FaceDetector full-range antes do landmarker.

# Landmarks do Face Mesh (478 pontos) no contorno central dos lábios.
LIPS_TOP_IDX = (13, 312, 311, 310, 415)
LIPS_BOTTOM_IDX = (14, 317, 402, 318, 324)

TARGET_ASPECT = 9 / 16
IOU_MATCH_THRESHOLD = 0.3
MIN_KEYFRAME_SPACING = 0.05
BASE_EPSILON = 0.002
SMOOTHING_WINDOW = 5
AUDIO_SAMPLE_RATE = 16000
AUDIO_WINDOW_SECONDS = 0.1
MAX_FACES = 4

OPENCV_ZOO_URL = (
    "https://github.com/opencv/opencv_zoo/raw/47534e27c9851bb1128ccc0102f1145e27f23f98/models"
)
YUNET_FILENAME = "face_detection_yunet_2023mar.onnx"
YUNET_URL = f"{OPENCV_ZOO_URL}/face_detection_yunet/{YUNET_FILENAME}"
SFACE_FILENAME = "face_recognition_sface_2021dec.onnx"
SFACE_URL = f"{OPENCV_ZOO_URL}/face_recognition_sface/{SFACE_FILENAME}"

FACE_TARGET = 0.28
MAX_BASE_ZOOM = 2.3
RUN_SHIFT = 0.07
STABLE_SECONDS = 0.5
SCENE_THRESHOLD = 0.3
MIN_FACE_WIDTH = 0.015
YUNET_SCORE = 0.6
LIP_CROP_SCALE = 2.2
LIP_CROP_SIZE = 256
FACE_HEADROOM = 0.12
DEFAULT_FACE_Y = 0.45
# Limiar de cosseno do SFace recomendado pelo OpenCV pra "mesma pessoa".
SAME_PERSON = 0.363
# ponytail: a troca seca leva CUT_HOLD (~2 frames a 30fps) porque o /reframe
# interpola linear entre keyframes e o editor descarta keyframes a menos de
# 0.05s. Corte de 1 frame exato pede um modo degrau no /reframe.
CUT_HOLD = 0.06

# ponytail: pesos calibrados no olho — a fala (labios x audio, ambos 0-1) manda,
# o tamanho do rosto (fração da área do frame, ~0.01-0.10) só desempata quando
# ninguém fala. Câmera pulando pra quem está calado = suba SPEECH_WEIGHT;
# câmera grudada no rosto mais próximo = baixe SIZE_WEIGHT.
SIZE_WEIGHT = 0.3
SPEECH_WEIGHT = 1.0

Box = tuple[float, float, float, float]
Point = tuple[float, float]


@dataclass
class TrackingResult:
    keyframes: list[dict[str, Any]]
    speakers: list[dict[str, Any]]
    source: dict[str, Any]


@dataclass
class _Observation:
    t: float
    track_id: int
    center_x: float
    area_fraction: float
    lip_activity: float
    face_width: float = 0.0
    face_y: float = 0.5


def iou(a: Box, b: Box) -> float:
    overlap_w = max(0.0, min(a[0] + a[2], b[0] + b[2]) - max(a[0], b[0]))
    overlap_h = max(0.0, min(a[1] + a[3], b[1] + b[3]) - max(a[1], b[1]))
    intersection = overlap_w * overlap_h
    if intersection <= 0.0:
        return 0.0
    union = a[2] * a[3] + b[2] * b[3] - intersection
    return intersection / union if union > 0.0 else 0.0


class FaceIdentityTracker:
    """Ids estáveis por rosto via IoU com o último frame em que cada um apareceu.

    O casamento é por sobreposição de bbox, NUNCA por posição no array: o
    MediaPipe reordena os rostos entre frames, e comparar a boca da pessoa A
    com o histórico da pessoa B transforma a atividade labial em ruído.
    """

    def __init__(self, threshold: float = IOU_MATCH_THRESHOLD) -> None:
        self._threshold = threshold
        self._next_id = 1
        # ponytail: nenhum id é despejado — um clipe de 3 min tem punhado de
        # rostos. Se virar vídeo longo, expire por "não visto há N frames".
        self._last_seen: dict[int, Box] = {}

    def assign(self, boxes: Sequence[Box]) -> list[int]:
        candidates = sorted(
            (
                (iou(box, known), index, track_id)
                for index, box in enumerate(boxes)
                for track_id, known in self._last_seen.items()
            ),
            reverse=True,
        )

        matched: dict[int, int] = {}
        used_tracks: set[int] = set()
        for score, index, track_id in candidates:
            if score < self._threshold:
                break
            if index in matched or track_id in used_tracks:
                continue
            matched[index] = track_id
            used_tracks.add(track_id)

        ids: list[int] = []
        for index, box in enumerate(boxes):
            matched_id = matched.get(index)
            if matched_id is None:
                matched_id = self._next_id
                self._next_id += 1
            self._last_seen[matched_id] = box
            ids.append(matched_id)
        return ids

    def forget(self) -> None:
        """Corte de cena: o rosto na mesma posição pode ser outra pessoa."""
        self._last_seen.clear()


def region_for_center(center_x: float, source_width: int, source_height: int) -> dict[str, float]:
    # float() explícito: o centro vem de um array numpy, e np.float64 vazaria
    # pro payload inteiro (o json.dumps engole por ser subclasse de float, mas
    # um encoder mais estrito, tipo orjson, não engoliria).
    width = min(1.0, TARGET_ASPECT / (source_width / source_height))
    x = min(max(float(center_x) / source_width - width / 2, 0.0), 1.0 - width)
    return {"x": round(x, 4), "y": 0.0, "w": round(width, 4), "h": 1.0}


def rdp(points: Sequence[Point], epsilon: float) -> list[Point]:
    """Ramer-Douglas-Peucker iterativo (pilha, não recursão: séries de vídeo
    longo estourariam o limite de recursão do Python)."""
    if len(points) < 3:
        return list(points)

    keep = [False] * len(points)
    keep[0] = keep[-1] = True
    stack = [(0, len(points) - 1)]

    while stack:
        first, last = stack.pop()
        if last <= first + 1:
            continue
        farthest, distance = _farthest_point(points, first, last)
        if distance > epsilon:
            keep[farthest] = True
            stack.append((first, farthest))
            stack.append((farthest, last))

    return [point for point, kept in zip(points, keep, strict=True) if kept]


def simplify_curve(points: Sequence[Point], max_points: int) -> list[Point]:
    """Menor epsilon (busca binária) que faz o RDP caber em `max_points`."""
    if max_points <= 1:
        return [points[0]] if points else []

    # BASE_EPSILON já derruba trecho parado: plano fixo vira 2 keyframes em vez
    # de um por amostra — cada keyframe é um nível de if() no filtro do /reframe.
    points = rdp(points, BASE_EPSILON)
    if len(points) <= max_points:
        return list(points)

    values = [point[1] for point in points]
    low, high = BASE_EPSILON, (max(values) - min(values)) + 1.0
    best = [points[0], points[-1]]
    for _ in range(40):
        middle = (low + high) / 2
        simplified = rdp(points, middle)
        if len(simplified) <= max_points:
            best = simplified
            high = middle
        else:
            low = middle
    return best


def moving_average(values: npt.NDArray[np.float64], window: int) -> npt.NDArray[np.float64]:
    # ponytail: média móvel em vez de savgol (scipy) — uma dependência a menos e,
    # pra trajetória de crop, câmera calma vale mais que preservar pico.
    if window < 2 or values.size < window:
        return values
    left = window // 2
    padded = np.pad(values, (left, window - 1 - left), mode="edge")
    return np.convolve(padded, np.ones(window) / window, mode="valid")


def build_keyframes(
    timestamps: Sequence[float],
    centers: Sequence[float],
    source_width: int,
    source_height: int,
    max_keyframes: int,
) -> list[dict[str, Any]]:
    if not timestamps:
        return [_keyframe(0.0, source_width / 2, source_width, source_height)]

    regions = [region_for_center(center, source_width, source_height) for center in centers]
    curve: list[Point] = [
        (float(t), region["x"]) for t, region in zip(timestamps, regions, strict=True)
    ]
    # O /reframe do serviço video precisa de um ponto em t=0 pra ancorar o crop.
    curve[0] = (0.0, curve[0][1])

    width = regions[0]["w"]
    keyframes: list[dict[str, Any]] = []
    for t, x in simplify_curve(curve, max_keyframes):
        if keyframes and t - keyframes[-1]["t"] < MIN_KEYFRAME_SPACING:
            continue
        keyframes.append(
            {
                "t": round(t, 3),
                "mode": "vertical",
                "regions": [{"x": round(x, 4), "y": 0.0, "w": width, "h": 1.0}],
            }
        )
    return keyframes


def build_speakers(
    timestamps: Sequence[float],
    speaker_ids: Sequence[int | None],
    sample_period: float,
    duration: float,
) -> list[dict[str, Any]]:
    speakers: list[dict[str, Any]] = []
    current: dict[str, Any] | None = None

    for t, speaker in zip(timestamps, speaker_ids, strict=True):
        if speaker is None:
            current = None
            continue
        if current is not None and current["speaker"] == speaker:
            current["end"] = t + sample_period
            continue
        current = {"start": t, "end": t + sample_period, "speaker": speaker}
        speakers.append(current)

    limit = duration if duration > 0 else float("inf")
    return [
        {
            "start": round(item["start"], 3),
            "end": round(min(item["end"], limit), 3),
            "speaker": item["speaker"],
        }
        for item in speakers
    ]


def stable_runs(
    timestamps: Sequence[float],
    centers: Sequence[float | None],
    cuts: Sequence[float],
    duration: float,
    sample_fps: int,
) -> list[tuple[float, float]]:
    """Tomadas (início, centro x 0-1): cada corte de cena abre uma, e dentro
    dela o centro só troca quando o locutor fica fora de RUN_SHIFT por
    STABLE_SECONDS seguidos.

    ponytail: limiar fixo de 0.07 da largura; se tremer em plano aberto, suba RUN_SHIFT."""
    patience = max(1, round(STABLE_SECONDS * sample_fps))
    bounds = [0.0, *sorted(cut for cut in cuts if 0 < cut < duration), duration + 1]
    runs: list[tuple[float, float]] = []
    last = 0.5

    for bound_start, bound_end in itertools.pairwise(bounds):
        indexes = [index for index, t in enumerate(timestamps) if bound_start <= t < bound_end]
        values = [centers[index] for index in indexes]
        known = [value for value in values if value is not None]
        if not known:
            runs.append((bound_start, last))
            continue

        filled: list[float] = []
        previous = known[0]
        for value in values:
            previous = value if value is not None else previous
            filled.append(previous)

        start, current = bound_start, float(np.median(filled[:3]))
        group: list[float] = []
        pending: list[int] = []
        for position, value in enumerate(filled):
            if abs(value - current) <= RUN_SHIFT:
                group.append(value)
                pending = []
                continue
            pending.append(position)
            if len(pending) < patience:
                continue
            runs.append((start, float(np.median(group)) if group else current))
            start = timestamps[indexes[pending[0]]]
            group = [filled[index] for index in pending]
            current = float(np.median(group))
            pending = []

        runs.append((start, float(np.median(group)) if group else current))
        last = runs[-1][1]

    return runs


def stable_speakers(speaker_ids: Sequence[int | None], sample_fps: int) -> list[int | None]:
    """Mesma regra da câmera: o locutor só troca quando o novo segura
    STABLE_SECONDS seguidos — boca mexendo numa amostra não vira "pessoa"."""
    patience = max(1, round(STABLE_SECONDS * sample_fps))
    stable: list[int | None] = []
    current: int | None = None
    candidate: int | None = None
    streak = 0

    for speaker in speaker_ids:
        if speaker == current:
            streak = 0
        elif streak and speaker == candidate:
            streak += 1
        else:
            candidate, streak = speaker, 1
        stable.append(current)
        if streak >= patience:
            current, streak = candidate, 0
            stable[-patience:] = [current] * patience

    return stable


def run_framing(
    timestamps: Sequence[float],
    centers: Sequence[float | None],
    sizes: Sequence[tuple[float, float] | None],
    runs: Sequence[tuple[float, float]],
    source_width: int,
    source_height: int,
) -> list[tuple[float, float, float, float]]:
    """(início, centro x, zoom-base, centro y do rosto) por tomada: plano aberto
    ganha zoom até o rosto ocupar FACE_TARGET da largura do recorte."""
    crop_width = min(source_width, source_height * TARGET_ASPECT)
    framing: list[tuple[float, float, float, float]] = []

    for index, (start, x) in enumerate(runs):
        end = runs[index + 1][0] if index + 1 < len(runs) else float("inf")
        near = [
            size
            for t, center, size in zip(timestamps, centers, sizes, strict=True)
            if start <= t < end
            and center is not None
            and size is not None
            and abs(center - x) < RUN_SHIFT
        ]
        if not near:
            framing.append((start, x, 1.0, DEFAULT_FACE_Y))
            continue

        face_width = float(np.median([size[0] for size in near]))
        face_y = float(np.median([size[1] for size in near]))
        zoom = min(MAX_BASE_ZOOM, max(1.0, FACE_TARGET * crop_width / (face_width * source_width)))
        framing.append((start, x, zoom, face_y))

    return framing


def cut_region(
    center_x: float, zoom: float, face_y: float, source_width: int, source_height: int
) -> dict[str, float]:
    crop_height = source_height / zoom
    crop_width = min(source_width, crop_height * TARGET_ASPECT)
    center_y = (
        face_y * source_height + FACE_HEADROOM * crop_height if zoom > 1.05 else source_height / 2
    )
    x = min(max(center_x * source_width - crop_width / 2, 0.0), source_width - crop_width)
    y = min(max(center_y - crop_height / 2, 0.0), source_height - crop_height)
    return {
        "x": round(x / source_width, 4),
        "y": round(y / source_height, 4),
        "w": round(crop_width / source_width, 4),
        "h": round(crop_height / source_height, 4),
    }


def build_cut_keyframes(
    framing: Sequence[tuple[float, float, float, float]],
    cuts: Sequence[float],
    duration: float,
    source_width: int,
    source_height: int,
    max_keyframes: int,
) -> list[dict[str, Any]]:
    """Um keyframe por tomada mais um que segura o recorte anterior até CUT_HOLD
    antes da troca. Acima de `max_keyframes`, a tomada mais curta some na anterior
    — troca de locutor antes de corte de cena, que herdaria o recorte de outra câmera."""
    scene_starts = set(cuts)
    shots = list(framing)
    while len(shots) > 1 and 2 * len(shots) - 1 > max_keyframes:
        ends = [shot[0] for shot in shots[2:]] + [duration]
        lengths = [end - shot[0] for shot, end in zip(shots[1:], ends, strict=True)]
        drop = min(
            range(len(lengths)),
            key=lambda index: (shots[index + 1][0] in scene_starts, lengths[index]),
        )
        del shots[drop + 1]

    regions = [
        cut_region(x, zoom, face_y, source_width, source_height) for _, x, zoom, face_y in shots
    ]
    keyframes = [_cut_keyframe(0.0, regions[0])]
    for (start, *_), region in zip(shots[1:], regions[1:], strict=True):
        current = keyframes[-1]["regions"][0]
        if region == current:
            continue
        if start - CUT_HOLD - keyframes[-1]["t"] >= MIN_KEYFRAME_SPACING:
            keyframes.append(_cut_keyframe(start - CUT_HOLD, current))
        if start - keyframes[-1]["t"] >= MIN_KEYFRAME_SPACING:
            keyframes.append(_cut_keyframe(start, region))
    return keyframes


def cluster_identities(
    embeddings: dict[int, npt.NDArray[np.float64]], seen_at: dict[int, set[int]]
) -> dict[int, int]:
    """track id (IoU) -> pessoa, juntando tracks pela aparência (soma dos vetores
    SFace normalizados). Maior track primeiro; dois tracks que aparecem na mesma
    amostra nunca são a mesma pessoa."""
    people: list[tuple[npt.NDArray[np.float64], set[int]]] = []
    mapping: dict[int, int] = {}

    for track_id in sorted(embeddings, key=lambda key: (-len(seen_at[key]), key)):
        vector = embeddings[track_id]
        best, best_score = None, SAME_PERSON
        for index, (total, seen) in enumerate(people):
            if seen & seen_at[track_id]:
                continue
            score = float(vector @ total) / max(
                float(np.linalg.norm(vector) * np.linalg.norm(total)), 1e-9
            )
            if score >= best_score:
                best, best_score = index, score

        if best is None:
            people.append((vector.copy(), set(seen_at[track_id])))
            mapping[track_id] = len(people)
            continue

        total, seen = people[best]
        people[best] = (total + vector, seen | seen_at[track_id])
        mapping[track_id] = best + 1

    return mapping


def scene_cuts(video_path: Path) -> list[float]:
    command = [
        "ffmpeg",
        "-nostdin",
        "-i",
        str(video_path),
        "-vf",
        f"scale=-2:270,select='gt(scene,{SCENE_THRESHOLD})',showinfo",
        "-an",
        "-f",
        "null",
        "-",
    ]
    try:
        result = subprocess.run(  # noqa: S603
            command, capture_output=True, text=True, errors="ignore", check=False, timeout=300
        )
    except (OSError, subprocess.SubprocessError) as exception:
        logger.warning("detecção de cena indisponível (%s); seguindo só com o locutor", exception)
        return []

    if result.returncode != 0:
        logger.warning(
            "ffmpeg não detectou cenas (%s); seguindo só com o locutor",
            result.stderr[-300:].strip(),
        )
        return []

    return [float(value) for value in re.findall(r"pts_time:\s*([\d.]+)", result.stderr)]


def audio_energy(video_path: Path, timestamps: Sequence[float]) -> npt.NDArray[np.float64]:
    """RMS normalizado (0-1) numa janela curta ao redor de cada amostra.

    ffmpeg + numpy resolvem: nada de librosa, que nunca chegou a ser usado."""
    if not timestamps:
        return np.zeros(0)

    command = [
        "ffmpeg",
        "-nostdin",
        "-v",
        "error",
        "-i",
        str(video_path),
        "-vn",
        "-ac",
        "1",
        "-ar",
        str(AUDIO_SAMPLE_RATE),
        "-f",
        "s16le",
        "-acodec",
        "pcm_s16le",
        "pipe:1",
    ]
    silence = np.zeros(len(timestamps))
    try:
        result = subprocess.run(command, capture_output=True, check=False, timeout=300)  # noqa: S603
    except (OSError, subprocess.SubprocessError) as exception:
        logger.warning("áudio indisponível para ASD (%s); seguindo só com os lábios", exception)
        return silence

    if result.returncode != 0:
        logger.warning(
            "ffmpeg não extraiu áudio (%s); seguindo só com os lábios",
            result.stderr.decode("utf-8", errors="ignore").strip(),
        )
        return silence

    samples = np.frombuffer(result.stdout, dtype=np.int16).astype(np.float64) / 32768.0
    if samples.size == 0:
        return silence

    half = int(AUDIO_WINDOW_SECONDS * AUDIO_SAMPLE_RATE / 2)
    energies = np.zeros(len(timestamps))
    for index, t in enumerate(timestamps):
        center = int(t * AUDIO_SAMPLE_RATE)
        chunk = samples[max(0, center - half) : center + half]
        if chunk.size:
            energies[index] = float(np.sqrt(np.mean(chunk**2)))

    peak = float(energies.max())
    return energies / peak if peak > 0 else energies


def resolve_delegate(system: str, choice: str, has_nvidia: bool) -> str:
    """Delegate do MediaPipe: 'gpu' ou 'cpu'.

    macOS NUNCA usa GPU: o delegate Metal aborta o processo dentro do
    FaceLandmarker com um crash C++ que nem try/except pega."""
    normalized = choice.strip().lower() or "auto"
    if system == "Darwin":
        return "cpu"
    if normalized == "gpu":
        return "gpu"
    if normalized == "auto" and has_nvidia:
        return "gpu"
    return "cpu"


def has_nvidia_gpu() -> bool:
    if not (Path("/dev/dri").exists() or Path("/dev/nvidia0").exists()):
        return False
    try:
        result = subprocess.run(
            ["nvidia-smi", "--query-gpu=name", "--format=csv,noheader"],  # noqa: S607
            capture_output=True,
            text=True,
            check=False,
            timeout=5,
        )
    except (OSError, subprocess.SubprocessError):
        return False
    output = (result.stdout or "").strip().lower()
    return result.returncode == 0 and bool(output) and "not found" not in output


def ensure_model(models_dir: Path, filename: str = MODEL_FILENAME, url: str = MODEL_URL) -> Path:
    """Baixa o modelo no primeiro uso — modelo não vai pro git."""
    model_path = models_dir / filename
    if model_path.exists():
        return model_path

    import httpx

    models_dir.mkdir(parents=True, exist_ok=True)
    logger.info("baixando %s de %s", filename, url)
    temporary = model_path.with_suffix(".partial")
    with httpx.stream("GET", url, timeout=120.0, follow_redirects=True) as response:
        response.raise_for_status()
        with temporary.open("wb") as out:
            for chunk in response.iter_bytes():
                out.write(chunk)
    temporary.rename(model_path)
    return model_path


def _farthest_point(points: Sequence[Point], first: int, last: int) -> tuple[int, float]:
    start_x, start_y = points[first]
    end_x, end_y = points[last]
    span_x, span_y = end_x - start_x, end_y - start_y
    norm = (span_x**2 + span_y**2) ** 0.5

    farthest, best = first + 1, -1.0
    for index in range(first + 1, last):
        x, y = points[index]
        if norm == 0.0:
            distance = ((x - start_x) ** 2 + (y - start_y) ** 2) ** 0.5
        else:
            distance = abs(span_y * x - span_x * y + end_x * start_y - end_y * start_x) / norm
        if distance > best:
            farthest, best = index, distance
    return farthest, best


def _keyframe(t: float, center_x: float, source_width: int, source_height: int) -> dict[str, Any]:
    return {
        "t": round(t, 3),
        "mode": "vertical",
        "regions": [region_for_center(center_x, source_width, source_height)],
    }


def _cut_keyframe(t: float, region: dict[str, float]) -> dict[str, Any]:
    return {"t": round(t, 3), "mode": "vertical", "regions": [region]}


def _lip_openness(landmarks: Any, box_height: float) -> float:
    top = sum(landmarks[i].y for i in LIPS_TOP_IDX) / len(LIPS_TOP_IDX)
    bottom = sum(landmarks[i].y for i in LIPS_BOTTOM_IDX) / len(LIPS_BOTTOM_IDX)
    return float(abs(bottom - top)) / max(box_height, 1e-6)


def _landmark_box(landmarks: Any, width: int, height: int) -> Box:
    xs = [landmark.x for landmark in landmarks]
    ys = [landmark.y for landmark in landmarks]
    left, right = min(xs) * width, max(xs) * width
    top, bottom = min(ys) * height, max(ys) * height
    return (left, top, right - left, bottom - top)


def _build_landmarker(model_path: Path, delegate: str) -> Any:
    # Silencia o ruído nativo de TFLite/GLOG antes do import do mediapipe.
    os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "3")
    os.environ.setdefault("GLOG_minloglevel", "3")

    from mediapipe.tasks import python as mp_tasks
    from mediapipe.tasks.python import vision as mp_vision

    delegates = {
        "gpu": mp_tasks.BaseOptions.Delegate.GPU,
        "cpu": mp_tasks.BaseOptions.Delegate.CPU,
    }
    options = mp_vision.FaceLandmarkerOptions(
        base_options=mp_tasks.BaseOptions(
            model_asset_path=str(model_path), delegate=delegates[delegate]
        ),
        running_mode=mp_vision.RunningMode.IMAGE,
        num_faces=MAX_FACES,
        min_face_detection_confidence=0.5,
        min_face_presence_confidence=0.5,
        min_tracking_confidence=0.5,
    )
    return mp_vision.FaceLandmarker.create_from_options(options)


def _open_capture(video_path: Path) -> tuple[Any, int, int, float, float]:
    import cv2

    capture = cv2.VideoCapture(str(video_path))
    if not capture.isOpened():
        raise RuntimeError(f"não foi possível abrir o vídeo: {video_path}")

    width = int(capture.get(cv2.CAP_PROP_FRAME_WIDTH))
    height = int(capture.get(cv2.CAP_PROP_FRAME_HEIGHT))
    fps = float(capture.get(cv2.CAP_PROP_FPS)) or 30.0
    frame_count = int(capture.get(cv2.CAP_PROP_FRAME_COUNT))
    duration = frame_count / fps if frame_count > 0 else 0.0
    if width <= 0 or height <= 0:
        capture.release()
        raise RuntimeError(f"vídeo sem dimensões legíveis: {video_path}")

    return capture, width, height, fps, duration


def _collect_observations(
    video_path: Path, sample_fps: int, model_path: Path, delegate: str
) -> tuple[list[float], list[list[_Observation]], int, int, float]:
    import cv2
    import mediapipe as mp

    capture, width, height, fps, duration = _open_capture(video_path)

    stride = max(1, round(fps / max(1, sample_fps)))
    landmarker = _build_landmarker(model_path, delegate)
    identities = FaceIdentityTracker()
    previous_lip_open: dict[int, float] = {}
    frame_area = float(width * height)

    timestamps: list[float] = []
    per_frame: list[list[_Observation]] = []
    index = 0

    try:
        while True:
            ok, frame = capture.read()
            if not ok:
                break
            if index % stride:
                index += 1
                continue

            t = index / fps
            index += 1
            timestamps.append(t)

            image = mp.Image(
                image_format=mp.ImageFormat.SRGB, data=cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
            )
            faces = landmarker.detect(image).face_landmarks or []
            boxes = [_landmark_box(landmarks, width, height) for landmarks in faces]
            track_ids = identities.assign(boxes)

            observations: list[_Observation] = []
            for landmarks, box, track_id in zip(faces, boxes, track_ids, strict=True):
                lip_open = _lip_openness(landmarks, box[3] / height)
                previous = previous_lip_open.get(track_id)
                previous_lip_open[track_id] = lip_open
                observations.append(
                    _Observation(
                        t=t,
                        track_id=track_id,
                        center_x=box[0] + box[2] / 2,
                        area_fraction=(box[2] * box[3]) / frame_area,
                        lip_activity=abs(lip_open - previous) if previous is not None else 0.0,
                    )
                )
            per_frame.append(observations)
    finally:
        capture.release()
        landmarker.close()

    if duration <= 0.0 and timestamps:
        duration = timestamps[-1] + 1.0 / max(1, sample_fps)

    return timestamps, per_frame, width, height, duration


def _crop_lip_openness(landmarker: Any, frame: Any, box: Box) -> float | None:
    """O FaceLandmarker é short-range: o rosto do YuNet vai recortado e ampliado."""
    import cv2
    import mediapipe as mp

    x, y, box_width, box_height = box
    frame_height, frame_width = frame.shape[:2]
    side = LIP_CROP_SCALE * max(box_width, box_height)
    left = int(max(0.0, x + box_width / 2 - side / 2))
    top = int(max(0.0, y + box_height / 2 - side / 2))
    crop = frame[
        top : int(min(frame_height, top + side)), left : int(min(frame_width, left + side))
    ]
    if not crop.size:
        return None

    resized = cv2.resize(crop, (LIP_CROP_SIZE, LIP_CROP_SIZE))
    image = mp.Image(
        image_format=mp.ImageFormat.SRGB, data=cv2.cvtColor(resized, cv2.COLOR_BGR2RGB)
    )
    faces = landmarker.detect(image).face_landmarks or []
    if not faces:
        return None

    crop_box = _landmark_box(faces[0], LIP_CROP_SIZE, LIP_CROP_SIZE)
    return _lip_openness(faces[0], crop_box[3] / LIP_CROP_SIZE)


def _collect_cut_observations(
    video_path: Path,
    sample_fps: int,
    model_paths: tuple[Path, Path, Path],
    delegate: str,
    cuts: Sequence[float],
) -> tuple[list[float], list[list[_Observation]], dict[int, int], int, int, float]:
    """ponytail: YuNet no frame inteiro + landmarker e SFace por rosto por amostra
    custam ~1x a duração do clip na CPU (medido: 63-119s pra 90s de 1080p). Se
    pesar, YuNet em meia resolução e SFace a cada N amostras do mesmo track."""
    import cv2

    landmarker_path, yunet_path, sface_path = model_paths
    capture, width, height, fps, duration = _open_capture(video_path)

    stride = max(1, round(fps / max(1, sample_fps)))
    landmarker = _build_landmarker(landmarker_path, delegate)
    detector: Any = cv2.FaceDetectorYN.create(str(yunet_path), "", (width, height), YUNET_SCORE)
    recognizer: Any = cv2.FaceRecognizerSF.create(str(sface_path), "")
    identities = FaceIdentityTracker()
    previous_lip_open: dict[int, float] = {}
    embeddings: dict[int, npt.NDArray[np.float64]] = {}
    seen_at: dict[int, set[int]] = {}
    pending_cuts = sorted(cuts)
    frame_area = float(width * height)

    timestamps: list[float] = []
    per_frame: list[list[_Observation]] = []
    index = 0

    try:
        while True:
            ok, frame = capture.read()
            if not ok:
                break
            if index % stride:
                index += 1
                continue

            t = index / fps
            index += 1
            if pending_cuts and pending_cuts[0] <= t:
                identities.forget()
                pending_cuts = [cut for cut in pending_cuts if cut > t]
            sample = len(timestamps)
            timestamps.append(t)

            _, faces = detector.detect(frame)
            confirmed: list[tuple[Any, Box, float]] = []
            for row in faces if faces is not None else []:
                if row[2] < width * MIN_FACE_WIDTH:
                    continue
                box = (float(row[0]), float(row[1]), float(row[2]), float(row[3]))
                lip_open = _crop_lip_openness(landmarker, frame, box)
                # O landmarker confirma o YuNet: estampa de camisa e mão passam
                # como rosto com score alto, e um deles vira "locutor".
                if lip_open is not None:
                    confirmed.append((row, box, lip_open))

            observations: list[_Observation] = []
            track_ids = identities.assign([box for _, box, _ in confirmed])
            for (row, box, lip_open), track_id in zip(confirmed, track_ids, strict=True):
                feature = (
                    recognizer.feature(recognizer.alignCrop(frame, row)).astype(np.float64).ravel()
                )
                normalized = feature / max(float(np.linalg.norm(feature)), 1e-9)
                embeddings[track_id] = (
                    embeddings.get(track_id, np.zeros_like(normalized)) + normalized
                )
                seen_at.setdefault(track_id, set()).add(sample)

                previous = previous_lip_open.get(track_id)
                previous_lip_open[track_id] = lip_open
                observations.append(
                    _Observation(
                        t=t,
                        track_id=track_id,
                        center_x=box[0] + box[2] / 2,
                        area_fraction=(box[2] * box[3]) / frame_area,
                        lip_activity=abs(lip_open - previous) if previous is not None else 0.0,
                        face_width=box[2] / width,
                        face_y=(box[1] + box[3] / 2) / height,
                    )
                )
            per_frame.append(observations)
    finally:
        capture.release()
        landmarker.close()

    if duration <= 0.0 and timestamps:
        duration = timestamps[-1] + 1.0 / max(1, sample_fps)

    return timestamps, per_frame, cluster_identities(embeddings, seen_at), width, height, duration


def _track_cuts(
    video_path: Path,
    *,
    max_keyframes: int,
    sample_fps: int,
    model_paths: tuple[Path, Path, Path],
    delegate: str,
) -> TrackingResult:
    cuts = scene_cuts(video_path)
    timestamps, per_frame, people, width, height, duration = _collect_cut_observations(
        video_path, sample_fps, model_paths, delegate, cuts
    )
    energies = audio_energy(video_path, timestamps)

    peak_activity = max(
        (observation.lip_activity for frame in per_frame for observation in frame), default=0.0
    )

    centers: list[float | None] = []
    sizes: list[tuple[float, float] | None] = []
    persons: list[int | None] = []

    for frame, energy in zip(per_frame, energies, strict=True):
        chosen = _pick_speaker(frame, float(energy), peak_activity)
        if chosen is None:
            centers.append(None)
            sizes.append(None)
            persons.append(None)
            continue
        centers.append(chosen.center_x / width)
        sizes.append((chosen.face_width, chosen.face_y))
        persons.append(people[chosen.track_id])

    order: dict[int, int] = {}
    speaker_ids = [
        None if person is None else order.setdefault(person, len(order) + 1)
        for person in stable_speakers(persons, sample_fps)
    ]
    runs = stable_runs(timestamps, centers, cuts, duration, sample_fps)
    framing = run_framing(timestamps, centers, sizes, runs, width, height)
    logger.info(
        "style=cuts: %d cortes de cena, %d tomadas, %d pessoas", len(cuts), len(runs), len(order)
    )

    return TrackingResult(
        keyframes=build_cut_keyframes(framing, cuts, duration, width, height, max_keyframes),
        speakers=build_speakers(timestamps, speaker_ids, 1.0 / max(1, sample_fps), duration),
        source={"width": width, "height": height, "duration": round(duration, 3)},
    )


def track(
    video_path: Path,
    *,
    max_keyframes: int,
    sample_fps: int,
    models_dir: Path,
    delegate: str,
    style: str = "smooth",
) -> TrackingResult:
    model_path = ensure_model(models_dir)
    resolved = resolve_delegate(platform.system(), delegate, has_nvidia_gpu())
    logger.info(
        "face tracking de %s (style=%s, delegate=%s, %d fps)",
        video_path.name,
        style,
        resolved,
        sample_fps,
    )

    if style == "cuts":
        return _track_cuts(
            video_path,
            max_keyframes=max_keyframes,
            sample_fps=sample_fps,
            model_paths=(
                model_path,
                ensure_model(models_dir, YUNET_FILENAME, YUNET_URL),
                ensure_model(models_dir, SFACE_FILENAME, SFACE_URL),
            ),
            delegate=resolved,
        )

    timestamps, per_frame, width, height, duration = _collect_observations(
        video_path, sample_fps, model_path, resolved
    )
    energies = audio_energy(video_path, timestamps)

    peak_activity = max(
        (observation.lip_activity for frame in per_frame for observation in frame), default=0.0
    )

    centers: list[float] = []
    speaker_ids: list[int | None] = []
    last_center = width / 2

    for frame, energy in zip(per_frame, energies, strict=True):
        chosen = _pick_speaker(frame, float(energy), peak_activity)
        if chosen is None:
            centers.append(last_center)
            speaker_ids.append(None)
            continue
        last_center = chosen.center_x
        centers.append(chosen.center_x)
        speaker_ids.append(chosen.track_id)

    smoothed = moving_average(np.array(centers, dtype=np.float64), SMOOTHING_WINDOW)
    sample_period = 1.0 / max(1, sample_fps)

    return TrackingResult(
        keyframes=build_keyframes(timestamps, list(smoothed), width, height, max_keyframes),
        speakers=build_speakers(timestamps, speaker_ids, sample_period, duration),
        source={"width": width, "height": height, "duration": round(duration, 3)},
    )


def _pick_speaker(
    observations: Sequence[_Observation], energy: float, peak_activity: float
) -> _Observation | None:
    if not observations:
        return None

    def score(observation: _Observation) -> float:
        activity = observation.lip_activity / peak_activity if peak_activity > 0 else 0.0
        return SIZE_WEIGHT * observation.area_fraction + SPEECH_WEIGHT * activity * energy

    return max(observations, key=score)
