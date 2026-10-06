/**
 * Monta o -filter_complex que reproduz o preview do /editor-de-video: crop
 * animado (pan/zoom com interpolação linear entre keyframes) via zoompan,
 * um trecho por modo de enquadramento (troca de modo é degrau), concat no fim.
 *
 * Coordenadas dos keyframes são normalizadas (0–1) sobre o tamanho REAL da
 * fonte (ffprobe), não o do preview. O tempo do zoompan é `in/fps` — cada
 * trecho passa por trim+setpts, então o frame 0 é o início do trecho.
 *
 * ponytail: o zoompan clampa zoom em 10 — região menor que ~10% da largura
 * renderiza mais aberta que o preview. Se doer, o upgrade é pré-cropar o
 * bounding box das janelas do trecho antes do zoompan.
 */

export interface ReframeRegion {
    x: number;
    y: number;
    w: number;
    h: number;
}

export interface ReframeKeyframe {
    t: number;
    mode: string;
    regions: ReframeRegion[];
}

export type ReframeOverlayKind = 'card' | 'small' | 'emoji' | 'meme' | 'meme_clip';

export interface ReframeOverlay {
    key: string;
    t: [number, number];
    kind: ReframeOverlayKind;
    pos: { x: number; y: number } | null;
}

export interface ReframeSfx {
    key: string;
    t: number;
    gainDb: number;
}

export interface ReframeRenderSettings {
    background: string;
    captions: boolean;
    captionColor: string;
    captionCase: string;
    speakerColors: Record<string, string>;
}

interface Slot {
    w: number;
    h: number;
}

interface ModeRun {
    mode: string;
    start: number;
    end: number;
    keyframes: ReframeKeyframe[];
}

const OUT_W = 1080;
const OUT_H = 1920;

const MAX_KEYFRAMES_PER_RUN = 80;

const ANIMATED_IMAGE = /\.(gif|apng)$/iu;

const OVERLAY_LAYOUTS: Record<ReframeOverlayKind, { fit: string; position: string }> = {
    card: {
        fit: 'scale=w=1000:h=800:force_original_aspect_ratio=decrease,pad=w=iw+32:h=ih+32:x=16:y=16:color=white',
        position: 'x=540-overlay_w/2:y=730-overlay_h/2',
    },
    small: {
        fit: 'scale=w=200:h=200:force_original_aspect_ratio=decrease',
        position: 'x=540-overlay_w/2:y=1190-overlay_h',
    },
    emoji: {
        fit: 'scale=w=150:h=150:force_original_aspect_ratio=decrease',
        position: 'x=540-overlay_w/2:y=1190-overlay_h',
    },
    meme: {
        fit: 'scale=w=200:h=200:force_original_aspect_ratio=decrease',
        position: 'x=540-overlay_w/2:y=1190-overlay_h',
    },
    meme_clip: {
        fit: 'scale=w=1080:h=1920:force_original_aspect_ratio=decrease,pad=w=1080:h=1920:x=(ow-iw)/2:y=(oh-ih)/2:color=black',
        position: 'x=0:y=0',
    },
};

const MODE_SLOTS: Record<string, Slot[]> = {
    vertical: [{ w: OUT_W, h: OUT_H }],
    split: [
        { w: OUT_W, h: OUT_H / 2 },
        { w: OUT_W, h: OUT_H / 2 },
    ],
    trio: [
        { w: OUT_W, h: OUT_H / 3 },
        { w: OUT_W, h: OUT_H / 3 },
        { w: OUT_W, h: OUT_H / 3 },
    ],
    centered: [{ w: OUT_W, h: OUT_H }],
};

export class ReframeFilterBuilder {
    public build(
        keyframes: ReframeKeyframe[],
        background: string,
        sourceWidth: number,
        sourceHeight: number,
        duration: number,
        fps: number,
        assFile: string | null,
        overlays: ReframeOverlay[] = [],
        sfx: ReframeSfx[] = [],
    ): string {
        const runs = this.modeRuns(keyframes, duration);
        const parts: string[] = [];

        const splitLabels = runs.map((_, index) => `[b${String(index)}]`).join('');
        parts.push(`[0:v]fps=${this.num(fps)},split=${String(runs.length)}${splitLabels}`);

        runs.forEach((run, index) => {
            parts.push(...this.runChain(run, index, sourceWidth, sourceHeight, fps, background));
        });

        const concatInputs = runs.map((_, index) => `[r${String(index)}v]`).join('');
        const concat = `${concatInputs}concat=n=${String(runs.length)}:v=1:a=0`;
        const subtitles = assFile === null ? '' : `,ass=${assFile}`;

        if (overlays.length === 0) {
            parts.push(`${concat}${subtitles}[vout]`);
        } else {
            parts.push(`${concat}[vc]`, ...this.overlayChain(overlays, fps));
            parts.push(`[v${String(overlays.length - 1)}]null${subtitles}[vout]`);
        }

        if (sfx.length > 0) {
            parts.push(this.sfxMix(sfx, '[0:a]', overlays.length + 1));
        }

        return parts.join(';');
    }

    /**
     * Inputs extras na ordem que o build() numera: 1 por aparição de overlay
     * (1..N), com janela de `-t` do tamanho da aparição, e 1 por key distinta
     * de SFX (N+1..). A key é o caminho relativo ao diretório do job.
     */
    public inputArgs(overlays: ReframeOverlay[], sfx: ReframeSfx[]): string[] {
        const args: string[] = [];

        for (const overlay of overlays) {
            const window = ['-t', this.num(overlay.t[1] - overlay.t[0]), '-i', overlay.key];

            if (overlay.kind === 'meme_clip') {
                args.push(...window);
                continue;
            }

            if (ANIMATED_IMAGE.test(overlay.key)) {
                args.push('-ignore_loop', '0', ...window);
                continue;
            }

            args.push('-loop', '1', '-framerate', '30', ...window);
        }

        for (const key of this.sfxKeys(sfx)) {
            args.push('-i', key);
        }

        return args;
    }

    /** Trechos contíguos de mesmo modo; o primeiro sempre começa em t=0. */
    private modeRuns(keyframes: ReframeKeyframe[], duration: number): ModeRun[] {
        const runs: ModeRun[] = [];

        for (const keyframe of keyframes) {
            const current = runs.at(-1);

            if (current !== undefined && current.mode === keyframe.mode) {
                current.keyframes.push(keyframe);
                continue;
            }

            runs.push({
                mode: keyframe.mode,
                start: current === undefined ? 0 : keyframe.t,
                end: duration,
                keyframes: [keyframe],
            });
        }

        if (runs.length === 0) {
            throw new Error('Render de reframe sem keyframes.');
        }

        for (const [index, run] of runs.entries()) {
            const next = runs[index + 1];
            run.end = next === undefined ? duration : next.start;
        }

        return runs
            .flatMap((run) => this.splitRun(run))
            .filter((run) => run.end - run.start > 0.001);
    }

    /**
     * Cada keyframe aninha um if() na expressão do zoompan, e o parser do
     * ffmpeg (pilha de ~100 níveis) recusa acima de ~94 keyframes. O trecho
     * vira pedaços de até 80, cada um começando no último keyframe do
     * anterior: a interpolação segue contínua e o trim+concat já costura.
     */
    private splitRun(run: ModeRun): ModeRun[] {
        const pieces: ModeRun[] = [];
        let rest = run;

        while (rest.keyframes.length > MAX_KEYFRAMES_PER_RUN) {
            const head = rest.keyframes.slice(0, MAX_KEYFRAMES_PER_RUN);
            const boundary = head.at(-1)!.t;

            pieces.push({ ...rest, end: boundary, keyframes: head });
            rest = {
                ...rest,
                start: boundary,
                keyframes: rest.keyframes.slice(MAX_KEYFRAMES_PER_RUN - 1),
            };
        }

        pieces.push(rest);

        return pieces;
    }

    private runChain(
        run: ModeRun,
        index: number,
        vw: number,
        vh: number,
        fps: number,
        background: string,
    ): string[] {
        const slots = MODE_SLOTS[run.mode];

        if (slots === undefined) {
            throw new Error(`Modo de reframe desconhecido: ${run.mode}.`);
        }

        const label = String(index);
        const trim = `[b${label}]trim=start=${this.num(run.start)}:end=${this.num(run.end)},setpts=PTS-STARTPTS`;
        const parts: string[] = [];

        if (slots.length === 1) {
            const chain =
                run.mode === 'centered'
                    ? this.containChain(run, 0, vw, vh, fps, background)
                    : this.coverChain(run, 0, slots[0]!, vw, vh, fps);
            parts.push(`${trim},${chain}[r${label}v]`);

            return parts;
        }

        const slotLabels = slots.map((_, slot) => `[s${label}_${String(slot)}]`).join('');
        parts.push(`${trim},split=${String(slots.length)}${slotLabels}`);

        slots.forEach((slot, slotIndex) => {
            const chain = this.coverChain(run, slotIndex, slot, vw, vh, fps);
            parts.push(`[s${label}_${String(slotIndex)}]${chain}[c${label}_${String(slotIndex)}]`);
        });

        const stackInputs = slots.map((_, slot) => `[c${label}_${String(slot)}]`).join('');
        parts.push(`${stackInputs}vstack=inputs=${String(slots.length)}[r${label}v]`);

        return parts;
    }

    /**
     * Cover: pad até a proporção do slot (a fonte fica no canto 0,0 — as
     * coordenadas continuam em pixels da fonte) e zoompan anima a janela.
     */
    private coverChain(
        run: ModeRun,
        slotIndex: number,
        slot: Slot,
        vw: number,
        vh: number,
        fps: number,
    ): string {
        const slotRatio = slot.w / slot.h;
        const padW = vw / vh >= slotRatio ? vw : this.even(vh * slotRatio);
        const padH = vw / vh >= slotRatio ? this.even(vw / slotRatio) : vh;

        const zoom = `${this.num(padW)}/(${this.expr(run, slotIndex, fps, (r) => r.w * vw)})`;
        const x = this.expr(run, slotIndex, fps, (r) => r.x * vw);
        const y = this.expr(run, slotIndex, fps, (r) => r.y * vh);

        return (
            `pad=w=${String(padW)}:h=${String(padH)}:x=0:y=0:color=black,` +
            `zoompan=z='${zoom}':x='${x}':y='${y}':d=1:fps=${this.num(fps)}:s=${String(slot.w)}x${String(slot.h)}`
        );
    }

    /**
     * Contain (Centrado): a região tem a proporção da fonte, então a caixa de
     * conteúdo dentro do 1080x1920 é constante — zoompan anima só a janela e o
     * pad pinta as barras com a cor escolhida.
     */
    private containChain(
        run: ModeRun,
        slotIndex: number,
        vw: number,
        vh: number,
        fps: number,
        background: string,
    ): string {
        const scale = Math.min(OUT_W / vw, OUT_H / vh);
        const contentW = this.even(vw * scale);
        const contentH = this.even(vh * scale);
        const color = `0x${background.replace(/^#/u, '')}`;

        const zoom = `${this.num(vw)}/(${this.expr(run, slotIndex, fps, (r) => r.w * vw)})`;
        const x = this.expr(run, slotIndex, fps, (r) => r.x * vw);
        const y = this.expr(run, slotIndex, fps, (r) => r.y * vh);

        return (
            `zoompan=z='${zoom}':x='${x}':y='${y}':d=1:fps=${this.num(fps)}:s=${String(contentW)}x${String(contentH)},` +
            `pad=w=${String(OUT_W)}:h=${String(OUT_H)}:x=${String(Math.round((OUT_W - contentW) / 2))}:y=${String(Math.round((OUT_H - contentH) / 2))}:color=${color}`
        );
    }

    /**
     * Piecewise linear no tempo local do trecho: constante antes do primeiro
     * keyframe e depois do último, lerp entre vizinhos.
     */
    private expr(
        run: ModeRun,
        slotIndex: number,
        fps: number,
        value: (region: ReframeRegion) => number,
    ): string {
        const time = `(in/${this.num(fps)})`;
        const points = run.keyframes.map((keyframe) => {
            const region = keyframe.regions[slotIndex];

            if (region === undefined) {
                throw new Error(
                    `Keyframe t=${String(keyframe.t)} (${keyframe.mode}) sem região para o slot ${String(slotIndex)}.`,
                );
            }

            return { t: keyframe.t - run.start, v: value(region) };
        });

        const first = points[0]!;

        if (points.length === 1) {
            return this.num(first.v);
        }

        let expression = this.num(points.at(-1)!.v);

        for (let index = points.length - 2; index >= 0; index -= 1) {
            const a = points[index]!;
            const b = points[index + 1]!;
            const span = Math.max(b.t - a.t, 0.001);
            const lerp = `${this.num(a.v)}+(${this.num(b.v)}-${this.num(a.v)})*(${time}-${this.num(a.t)})/${this.num(span)}`;
            expression = `if(lt(${time},${this.num(b.t)}),${lerp},${expression})`;
        }

        return `if(lt(${time},${this.num(first.t)}),${this.num(first.v)},${expression})`;
    }

    /**
     * Cada aparição é um input com janela: setpts empurra o frame 0 para `a` e
     * eof_action=pass tira a figurinha no fim da janela (o default `repeat`
     * congela o último frame até o fim do vídeo). O framesync do overlay
     * encerra a entrada no pts do ÚLTIMO frame, não no fim dele — sem o fps=,
     * o último frame de um GIF (ou de qualquer input) não aparece.
     *
     * ponytail: o meme_clip é sobreposto na linha do tempo com a fala seguindo
     * por baixo. O insert de verdade (pausa a fonte e estica o tempo, como o
     * gusta faz) depende da Timeline do jump cut.
     *
     * ponytail: sem `pos`, small/emoji/meme ficam com a base fixa em y=1190
     * (acima de 2 linhas da legenda em 1360); legenda de 3 linhas, punch ou
     * legenda no topo encostam. Upgrade: ancorar pelo nº de linhas do bloco
     * ativo em `a` (o SubtitleBuilder sabe pelo wrap).
     */
    private overlayChain(overlays: ReframeOverlay[], fps: number): string[] {
        const parts: string[] = [];
        let video = '[vc]';

        overlays.forEach((overlay, index) => {
            const label = String(index);
            const layout = OVERLAY_LAYOUTS[overlay.kind];
            const position =
                overlay.pos === null
                    ? layout.position
                    : `x=${this.num(overlay.pos.x * OUT_W)}-overlay_w/2:y=${this.num(overlay.pos.y * OUT_H)}-overlay_h/2`;

            parts.push(
                `[${String(index + 1)}:v]fps=${this.num(fps)},format=rgba,${layout.fit},setpts=PTS-STARTPTS+${this.num(overlay.t[0])}/TB[o${label}]`,
            );
            parts.push(`${video}[o${label}]overlay=${position}:eof_action=pass[v${label}]`);
            video = `[v${label}]`;
        });

        return parts;
    }

    /**
     * Mistura os SFX sobre a voz. normalize=0 é obrigatório: o default divide
     * cada entrada pelo nº de entradas ativas e, com adelay, todas estão ativas
     * desde t=0 (a voz cai 29,6 dB com 30 SFX). O alimiter segura o pico com
     * level=0, porque o default (auto-ganho) mexeria no volume da voz.
     */
    private sfxMix(sfx: ReframeSfx[], voice: string, firstInput: number): string {
        const keys = this.sfxKeys(sfx);
        const parts = sfx.map(
            (item, index) =>
                `[${String(firstInput + keys.indexOf(item.key))}:a]volume=${this.num(item.gainDb)}dB,adelay=${String(Math.round(item.t * 1000))}:all=1[x${String(index)}]`,
        );
        const labels = sfx.map((_, index) => `[x${String(index)}]`).join('');
        parts.push(
            `${voice}${labels}amix=inputs=${String(sfx.length + 1)}:normalize=0:duration=first,alimiter=limit=0.97:level=0[aout]`,
        );

        return parts.join(';');
    }

    private sfxKeys(sfx: ReframeSfx[]): string[] {
        return [...new Set(sfx.map((item) => item.key))];
    }

    private even(value: number): number {
        return 2 * Math.round(value / 2);
    }

    private num(value: number): string {
        return String(Math.round(value * 10000) / 10000);
    }
}
