"""Self-check das funções puras do face tracking: `python -m app.facetracking.check`.

Sem framework e sem modelo: importa só o que não depende de mediapipe/cv2.
"""

from __future__ import annotations

import json
import sys

import numpy as np

from app.facetracking.tracker import (
    FaceIdentityTracker,
    build_keyframes,
    build_speakers,
    iou,
    moving_average,
    region_for_center,
    simplify_curve,
)


def check_curve_fits_max_keyframes() -> None:
    timestamps = [i / 6 for i in range(400)]
    centers = [960 + 400 * ((i // 7) % 2) for i in range(400)]

    keyframes = build_keyframes(timestamps, centers, 1920, 1080, 40)

    assert len(keyframes) <= 40, len(keyframes)
    assert len(keyframes) >= 2, keyframes
    assert keyframes[0]["t"] == 0.0, keyframes[0]
    assert [k["t"] for k in keyframes] == sorted(k["t"] for k in keyframes)
    assert all(k["mode"] == "vertical" and len(k["regions"]) == 1 for k in keyframes)
    assert all(keyframes[i]["t"] - keyframes[i - 1]["t"] >= 0.05 for i in range(1, len(keyframes)))

    curve = [(i / 6, float(i % 3)) for i in range(400)]
    assert len(simplify_curve(curve, 5)) <= 5
    assert len(simplify_curve(curve, 1)) == 1


def check_payload_is_json_serializable() -> None:
    # Os centros chegam de um array numpy: np.float64 passa em isinstance(x, float),
    # então só `type(...) is float` garante que o payload sai com float puro.
    smoothed = moving_average(np.array([960.0 + 200 * (i % 2) for i in range(60)]), 5)
    keyframes = build_keyframes([i / 6 for i in range(60)], list(smoothed), 1920, 1080, 40)

    json.dumps(keyframes)
    assert all(type(k["regions"][0]["x"]) is float for k in keyframes), keyframes[0]


def check_region_from_center() -> None:
    centered = region_for_center(960, 1920, 1080)
    assert centered == {"x": 0.3418, "y": 0.0, "w": 0.3164, "h": 1.0}, centered

    left = region_for_center(0, 1920, 1080)
    assert left["x"] == 0.0, left

    right = region_for_center(1920, 1920, 1080)
    assert right["x"] == round(1 - 0.31640625, 4) == 0.6836, right

    # Fonte já vertical: o recorte é o frame inteiro.
    assert region_for_center(540, 1080, 1920) == {"x": 0.0, "y": 0.0, "w": 1.0, "h": 1.0}


def check_identity_survives_reordering() -> None:
    left_face = (100.0, 100.0, 200.0, 200.0)
    right_face = (900.0, 100.0, 200.0, 200.0)

    tracker = FaceIdentityTracker()
    first = tracker.assign([left_face, right_face])
    # Mesmos rostos, ordem trocada pelo MediaPipe: os ids têm que acompanhar o
    # rosto, não a posição no array (é exatamente o bug que isso corrige).
    second = tracker.assign([right_face, left_face])

    assert first == [1, 2], first
    assert second == [2, 1], second

    third = tracker.assign([left_face, (400.0, 600.0, 150.0, 150.0)])
    assert third == [1, 3], third

    assert iou(left_face, left_face) == 1.0
    assert iou(left_face, right_face) == 0.0


def check_speakers() -> None:
    speakers = build_speakers([0.0, 0.2, 0.4, 0.6], [1, 1, None, 2], 0.2, 1.0)

    assert speakers == [
        {"start": 0.0, "end": 0.4, "speaker": 1},
        {"start": 0.6, "end": 0.8, "speaker": 2},
    ], speakers
    assert build_speakers([0.0], [None], 0.2, 1.0) == []


def main() -> None:
    for check in (
        check_curve_fits_max_keyframes,
        check_payload_is_json_serializable,
        check_region_from_center,
        check_identity_survives_reordering,
        check_speakers,
    ):
        check()
        sys.stdout.write(f"ok  {check.__name__}\n")
    sys.stdout.write("todos os checks passaram\n")


if __name__ == "__main__":
    main()
