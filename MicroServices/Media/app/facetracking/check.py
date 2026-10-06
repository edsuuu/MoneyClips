"""Self-check das funções puras do face tracking: `python -m app.facetracking.check`.

Sem framework e sem modelo: importa só o que não depende de mediapipe/cv2.
"""

from __future__ import annotations

import json
import sys

import numpy as np
import numpy.typing as npt

from app.facetracking.tracker import (
    FaceIdentityTracker,
    build_cut_keyframes,
    build_keyframes,
    build_speakers,
    cluster_identities,
    iou,
    moving_average,
    region_for_center,
    run_framing,
    simplify_curve,
    stable_runs,
    stable_speakers,
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


def check_stable_runs() -> None:
    timestamps = [i / 6 for i in range(36)]

    runs = stable_runs(timestamps, [0.3] * 18 + [0.7] * 18, [], 6.0, 6)
    assert runs[0][1] == 0.3 and runs[-1][1] == 0.7 and abs(runs[-1][0] - 3.0) < 0.01, runs

    # Locutor por menos de 0.5s não troca a câmera.
    blip = stable_runs(timestamps, [0.3] * 18 + [0.7] * 2 + [0.3] * 16, [], 6.0, 6)
    assert blip == [(0.0, 0.3)], blip

    # Corte de cena abre tomada nova mesmo sem troca de locutor.
    scene = stable_runs(timestamps, [0.3] * 36, [2.0], 6.0, 6)
    assert [start for start, _ in scene] == [0.0, 2.0], scene


def check_stable_speakers() -> None:
    # Troca confirmada depois de 0.5s volta pro início da fala; piscada some.
    assert stable_speakers([1, 1, 1, 2, 2, 1, 2, 2, 2], 6) == [1, 1, 1, 1, 1, 1, 2, 2, 2]
    # Rosto sumido por uma amostra não quebra a fala.
    assert stable_speakers([1, 1, 1, None, 1, 1], 6) == [1] * 6
    assert stable_speakers([1, 2, 1, 2], 6) == [None] * 4


def check_run_framing() -> None:
    framing = run_framing(
        [0.0, 0.2, 5.0],
        [0.3, 0.3, 0.7],
        [(0.04, 0.4), (0.04, 0.4), (0.3, 0.4)],
        [(0.0, 0.3), (4.0, 0.7)],
        1920,
        1080,
    )
    assert abs(framing[0][2] - 2.21) < 0.01 and framing[1][2] == 1.0, framing


def check_cut_keyframes() -> None:
    keyframes = build_cut_keyframes(
        [(0.0, 0.3, 1.0, 0.45), (4.0, 0.7, 2.0, 0.4)], [], 8.0, 1920, 1080, 40
    )

    # Troca seca: o recorte antigo segura até CUT_HOLD antes do novo.
    assert [k["t"] for k in keyframes] == [0.0, 3.94, 4.0], keyframes
    assert keyframes[1]["regions"] == keyframes[0]["regions"], keyframes
    assert keyframes[0]["regions"][0] == region_for_center(0.3 * 1920, 1920, 1080), keyframes[0]

    zoomed = keyframes[2]["regions"][0]
    assert zoomed["h"] == 0.5 and zoomed["w"] == round(0.31640625 / 2, 4), zoomed
    assert zoomed["y"] == round((0.4 * 1080 + 0.12 * 540 - 270) / 1080, 4), zoomed

    shots = [(float(i), 0.2 + 0.6 * (i % 2), 1.0, 0.45) for i in range(30)]
    capped = build_cut_keyframes(shots, [], 30.0, 1920, 1080, 9)
    assert len(capped) <= 9 and capped[0]["t"] == 0.0, capped

    # No teto, a troca de locutor (1.0) some antes da tomada de corte de cena
    # (2.0), mesmo ela sendo mais curta.
    shots = [
        (0.0, 0.2, 1.0, 0.45),
        (1.0, 0.8, 1.0, 0.45),
        (2.0, 0.5, 1.0, 0.45),
        (2.5, 0.8, 1.0, 0.45),
    ]
    scene = build_cut_keyframes(shots, [2.0], 8.0, 1920, 1080, 5)
    assert [k["t"] for k in scene] == [0.0, 1.94, 2.0, 2.44, 2.5], scene


def check_cut_payload_is_json_serializable() -> None:
    timestamps = [i / 6 for i in range(60)]
    centers: list[float | None] = [float(v) for v in np.array([0.25] * 30 + [0.75] * 30)]
    sizes: list[tuple[float, float] | None] = [(0.05, 0.4)] * 60
    runs = stable_runs(timestamps, centers, [], 10.0, 6)
    keyframes = build_cut_keyframes(
        run_framing(timestamps, centers, sizes, runs, 1920, 1080), [], 10.0, 1920, 1080, 40
    )

    json.dumps(keyframes)
    assert all(type(value) is float for k in keyframes for value in k["regions"][0].values())
    assert all(0.0 <= value <= 1.0 for k in keyframes for value in k["regions"][0].values())


def check_identity_clustering() -> None:
    a = np.array([1.0, 0.0, 0.0])
    b = np.array([0.0, 1.0, 0.0])
    people = cluster_identities(
        {1: a * 5.0, 2: b * 3.0, 3: a * 2.0 + 0.1 * b, 4: a},
        {1: {0, 1, 2, 3, 4}, 2: {0, 1, 2}, 3: {7, 8}, 4: {2}},
    )

    # Track 3 é o rosto do 1 depois de um corte de cena: mesma pessoa.
    assert people[3] == people[1], people
    assert people[2] != people[1], people
    # Mesmo rosto que o 1, mas na mesma amostra: são duas pessoas.
    assert people[4] not in (people[1], people[2]), people
    assert sorted(set(people.values())) == [1, 2, 3], people


def check_people_count_survives_camera_cuts() -> None:
    # Duas pessoas, três tomadas: a câmera troca, o IoU esquece (forget) e a
    # posição de cada uma muda. Sem a aparência, viram 6 "pessoas".
    rng = np.random.default_rng(7)
    faces = {"a": rng.normal(size=128), "b": rng.normal(size=128)}
    left, right = (100.0, 100.0, 200.0, 200.0), (900.0, 100.0, 200.0, 200.0)
    shots = [(("a", "b"), [left, right]), (("b", "a"), [left, right]), (("a", "b"), [right, left])]

    tracker = FaceIdentityTracker()
    embeddings: dict[int, npt.NDArray[np.float64]] = {}
    seen_at: dict[int, set[int]] = {}
    sample = 0
    for names, boxes in shots:
        tracker.forget()
        for _ in range(6):
            for name, track_id in zip(names, tracker.assign(boxes), strict=True):
                vector = faces[name] + rng.normal(scale=0.3, size=128)
                embeddings[track_id] = embeddings.get(
                    track_id, np.zeros(128)
                ) + vector / np.linalg.norm(vector)
                seen_at.setdefault(track_id, set()).add(sample)
            sample += 1

    assert len(embeddings) == 6, embeddings.keys()
    people = cluster_identities(embeddings, seen_at)
    assert len(set(people.values())) == 2, people
    assert people[1] == people[4] == people[5] and people[2] == people[3] == people[6], people


def main() -> None:
    for check in (
        check_curve_fits_max_keyframes,
        check_payload_is_json_serializable,
        check_region_from_center,
        check_identity_survives_reordering,
        check_speakers,
        check_stable_runs,
        check_stable_speakers,
        check_run_framing,
        check_cut_keyframes,
        check_cut_payload_is_json_serializable,
        check_identity_clustering,
        check_people_count_survives_camera_cuts,
    ):
        check()
        sys.stdout.write(f"ok  {check.__name__}\n")
    sys.stdout.write("todos os checks passaram\n")


if __name__ == "__main__":
    main()
