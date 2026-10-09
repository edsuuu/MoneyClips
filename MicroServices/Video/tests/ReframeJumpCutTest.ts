/**
 * Check do jump cut no /reframe (`cuts` + `dead_air`). O que quebra em
 * silêncio: o vídeo sai sem erro com o áudio fora de sincronia, a legenda no
 * tempo errado ou o payload antigo mudando de saída.
 *
 * - Timeline: o mapa clip → saída do render.py e o remap de cada lista.
 * - Golden: payload sem cuts/dead_air monta os MESMOS args de ffmpeg de antes
 *   (gerados do main antes deste PR).
 * - Fronteira: cuts fora de ordem, sobrepostos ou malformados viram 422.
 * - Render real curto (pulado sem ffmpeg): 2 cuts + 1 pausa de ar morto; as
 *   durações de vídeo e áudio do mp4 batem com a Timeline.
 *
 *   pnpm test
 */

import { strict as assert } from 'node:assert';
import { execFileSync, spawnSync } from 'node:child_process';
import { copyFileSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { ValidationError } from '@/Exceptions/ValidationError';
import { ReframeController } from '@/Http/Controllers/ReframeController';
import { FfmpegRunner } from '@/Services/Caption/FfmpegRunner';
import {
    ReframeFilterBuilder,
    type ReframeKeyframe,
} from '@/Services/Reframe/ReframeFilterBuilder';
import { type ReframeJob, ReframeQueueService } from '@/Services/Reframe/ReframeQueueService';
import { Timeline } from '@/Services/Reframe/Timeline';
import { Probe, type VideoMeta } from '@/Services/Video/Probe';
import type { WebhookPayload } from '@/Services/WebhookService';

const GOLDEN = join(dirname(fileURLToPath(import.meta.url)), 'golden', 'reframe-legacy-args.json');

const vertical = (t: number, x: number, w = 0.3164, h = 1): ReframeKeyframe => ({
    t,
    mode: 'vertical',
    regions: [{ x, y: 0, w, h }],
});

const keyframes: ReframeKeyframe[] = [
    vertical(0, 0.2),
    vertical(10, 0.5),
    {
        t: 30,
        mode: 'split',
        regions: [
            { x: 0, y: 0, w: 0.5, h: 0.4444 },
            { x: 0.5, y: 0.5, w: 0.5, h: 0.4444 },
        ],
    },
    { t: 60, mode: 'centered', regions: [{ x: 0.1, y: 0.1, w: 0.8, h: 0.8 }] },
];

const legacyPayloads: Record<string, Record<string, unknown>> = {
    bare: { keyframes },
    karaoke: {
        keyframes,
        settings: {
            captions: true,
            captionColor: '#ff0000',
            captionCase: 'upper',
            speakerColors: { '0': '#00ff00' },
            background: '#112233',
        },
        transcript: JSON.parse(
            readFileSync(join(dirname(GOLDEN), 'transcript.json'), 'utf8'),
        ) as unknown,
    },
    literal: {
        keyframes,
        captions: [
            { t: [1, 2.5], text: 'olá, pessoal', style: 'speech' },
            { t: [40, 42], text: 'nota', style: 'note', pos: 'top' },
        ],
        caption_preset: 'verde',
        watermark: '@unkvoid_clips',
        overlays: [
            { key: 'assets/emoji/x.png', t: [3, 4.5], kind: 'emoji' },
            { key: 'assets/memes/39.mp4', t: [50, 52], kind: 'meme_clip' },
        ],
        sfx: [
            { key: 'assets/sfx/hit.wav', t: 12.04, gain_db: -3 },
            { key: 'assets/memes/39.mp4', t: 50 },
        ],
    },
};

const fakeMeta: VideoMeta = {
    durationSeconds: 90,
    exactDurationSeconds: 90.04,
    width: 1920,
    height: 1080,
    videoCodec: 'h264',
    audioCodec: 'aac',
    videoBitrateKbps: 4000,
    hasAudio: true,
    fps: 30,
};

const enqueued = (body: Record<string, unknown>): ReframeJob => {
    let captured: ReframeJob | null = null;
    const queue = {
        enqueue: (job: Omit<ReframeJob, 'uuid'>): string => {
            captured = { ...job, uuid: 'job-uuid' };

            return 'job-uuid';
        },
    } as unknown as ReframeQueueService;

    new ReframeController(queue).create(
        {
            body: {
                edit_uuid: 'e',
                source_key: 'videos/s.mp4',
                output_key: 'videos/o.mp4',
                webhook_url: 'http://x',
                ...body,
            },
        } as never,
        { status: () => ({ json: () => undefined }) } as never,
    );

    assert.notEqual(captured, null, 'o controller precisa enfileirar o job');

    return captured as unknown as ReframeJob;
};

const refused = (body: Record<string, unknown>, field: string): void => {
    assert.throws(
        () => enqueued({ keyframes, ...body }),
        (error: unknown) =>
            error instanceof ValidationError &&
            Object.keys(error.details as Record<string, string>).includes(field),
        `esperava 422 em ${field}`,
    );
};

const ffmpegArgs = async (body: Record<string, unknown>): Promise<string[]> => {
    let args: string[] = [];
    const queue = new ReframeQueueService(
        {
            download: (_key: string, path: string): Promise<void> => {
                writeFileSync(path, '');
                return Promise.resolve();
            },
            uploadFile: (): Promise<void> => Promise.resolve(),
        } as never,
        { read: (): Promise<VideoMeta> => Promise.resolve(fakeMeta) } as never,
        {
            runWithFallback: (build: (encoderArgs: string[]) => string[]): Promise<void> => {
                args = build(['<encoder>']);
                return Promise.resolve();
            },
        } as never,
        undefined,
        undefined,
        undefined,
        { send: (): Promise<boolean> => Promise.resolve(true) } as never,
    );

    await queue['process'](enqueued(body));

    return args;
};

const ffmpegAvailable = (): boolean => {
    try {
        execFileSync('ffmpeg', ['-version'], { stdio: 'ignore' });
        return true;
    } catch {
        return false;
    }
};

const ffmpeg = (cwd: string, ...args: string[]): void => {
    execFileSync('ffmpeg', ['-hide_banner', '-v', 'error', '-y', ...args], { cwd });
};

const streamDurations = (path: string): Record<string, number> => {
    const raw = execFileSync('ffprobe', [
        '-v',
        'error',
        '-show_entries',
        'stream=codec_type,duration',
        '-of',
        'json',
        path,
    ]).toString();
    const parsed = JSON.parse(raw) as { streams: { codec_type: string; duration: string }[] };

    return Object.fromEntries(
        parsed.streams.map((stream) => [stream.codec_type, Number(stream.duration)]),
    );
};

async function main(): Promise<void> {
    {
        const timeline = new Timeline([
            [10, 20],
            [30, 35],
        ]);

        assert.equal(timeline.duration, 15);
        assert.deepEqual(
            [timeline.out(15), timeline.out(25), timeline.out(32), timeline.out(99)],
            [5, 10, 12, 15],
            'mesmo self_check do render.py',
        );
        assert.deepEqual(timeline.span(18, 32), [8, 12], 'janela que cruza o corte é aparada');
        assert.deepEqual(
            timeline.windows([
                { t: [18, 32] as [number, number], text: 'cruza' },
                { t: [21, 29] as [number, number], text: 'dentro do corte' },
            ]),
            [{ t: [8, 12], text: 'cruza' }],
            'o que cai inteiro num trecho removido sai',
        );
        assert.deepEqual(
            timeline.sfx([
                { key: 'assets/a.wav', t: 25, gainDb: 0 },
                { key: 'assets/b.wav', t: 31, gainDb: 0 },
                { key: 'assets/c.wav', t: 5, gainDb: 0 },
            ]),
            [{ key: 'assets/b.wav', t: 11, gainDb: 0 }],
            'sfx num trecho removido (ou antes do 1º keep) sai, o resto vai pro tempo de saída',
        );
        console.log('ok  Timeline: out, span, windows e sfx');
    }

    {
        assert.deepEqual(
            Timeline.fromRemoved(
                30,
                [
                    [20, 22],
                    [12, 13],
                ],
                30,
            ).keep,
            [
                [0, 12],
                [13, 20],
                [22, 30],
            ],
            'keep = [0, dur] − cuts (o keep_from do render.py)',
        );
        assert.deepEqual(
            Timeline.fromRemoved(30, [[5.12, 5.88]], 30).keep,
            [
                [0, 154 / 30],
                [176 / 30, 30],
            ],
            'bordas no grid de frames',
        );
        assert.deepEqual(
            Timeline.fromRemoved(
                30,
                [
                    [0, 2],
                    [1.5, 4],
                    [29.5, 30.5],
                ],
                30,
            ).keep,
            [[4, 29.5]],
            'corte de cabeça, sobreposição com o ar morto e rabo um pouco além do probe',
        );
        assert.deepEqual(
            Timeline.fromRemoved(
                30,
                [
                    [10, 12],
                    [12.05, 14],
                ],
                30,
            ).keep,
            [
                [0, 10],
                [14, 30],
            ],
            'trecho de menos de 0.1s entre dois cortes some',
        );
        assert.throws(() => Timeline.fromRemoved(30, [[25, 32]], 30), /fora do clip/u);
        assert.throws(() => Timeline.fromRemoved(30, [[0, 30]], 30), /clip inteiro/u);
        console.log('ok  Timeline.fromRemoved: subtração, grid de frames, cabeça/rabo e limites');
    }

    {
        const timeline = new Timeline([
            [0, 10],
            [12, 20],
        ]);
        const remapped = timeline.keyframes([vertical(0, 0.1), vertical(16, 0.5)]);

        assert.deepEqual(
            remapped.map((keyframe) => [
                keyframe.t,
                Math.round(keyframe.regions[0]!.x * 1e6) / 1e6,
            ]),
            [
                [0, 0.1],
                [10, 0.35],
                [10, 0.4],
                [14, 0.5],
                [18, 0.5],
            ],
            'cada borda leva o valor interpolado do clip e a emenda vira degrau',
        );

        const stable = timeline.keyframes([vertical(0, 0.2)]);
        const zoomed = stable[2]!.regions[0]!;

        assert.deepEqual(
            stable.map((keyframe) => keyframe.t),
            [0, 10, 10, 18],
        );
        assert.equal(stable[1]!.regions[0]!.w, 0.3164, 'antes da emenda: enquadramento original');
        assert.ok(
            Math.abs(zoomed.w - 0.3164 / 1.3) < 1e-9 && Math.abs(zoomed.h - 1 / 1.3) < 1e-9,
            'emenda sem troca de enquadramento ganha o degrau de 1.3×',
        );
        assert.ok(
            Math.abs(zoomed.x + zoomed.w / 2 - (0.2 + 0.3164 / 2)) < 1e-9,
            'o zoom é no centro da região',
        );
        assert.deepEqual(stable[3]!.regions[0], zoomed, 'o degrau segura até o fim da tomada');

        const laravelStep = timeline.keyframes([
            vertical(0, 0.2),
            vertical(12 - 1 / 30, 0.2),
            vertical(12, 0.2, 0.3164 / 1.3, 1 / 1.3),
        ]);
        assert.equal(
            laravelStep.filter((keyframe) => keyframe.regions[0]!.h < 0.7).length,
            0,
            'emenda que já chega com o degrau do Laravel não ganha outro (sem 1.69×)',
        );

        const shortShot = new Timeline([
            [0, 10],
            [12, 12.3],
            [14, 20],
        ]).keyframes([vertical(0, 0.2)]);
        assert.deepEqual(
            shortShot.map((keyframe) => keyframe.regions[0]!.h < 1),
            [false, false, true, true, true, true],
            'tomada < 0.4s não alterna de novo (min_shot do render.py)',
        );

        const onLaravelRhythm = timeline.keyframes([vertical(0, 0.3, 0.3164 / 1.3, 1 / 1.3)]);
        assert.ok(
            Math.abs(onLaravelRhythm[2]!.regions[0]!.h - 1) < 0.001 &&
                onLaravelRhythm.every((keyframe) => keyframe.regions[0]!.h > 0.76),
            'ritmo do Laravel já em 1.3×: o degrau abre de volta pra 1.0×, nunca empilha 1.69×',
        );
        console.log(
            'ok  Timeline.keyframes: bordas amostradas, degrau na emenda e jump cut escondido',
        );
    }

    {
        const timeline = new Timeline([
            [0, 4],
            [6, 9],
        ]);
        const graph = new ReframeFilterBuilder().build(
            timeline.keyframes([vertical(0, 0.2)]),
            '#000000',
            1920,
            1080,
            timeline.duration,
            30,
            null,
            [],
            [{ key: 'assets/sfx/hit.wav', t: 1, gainDb: 0 }],
            timeline,
        );

        assert.match(graph, /^\[0:v\]fps=30,split=2\[cv0\]\[cv1\];\[0:a\]asplit=2\[ca0\]\[ca1\];/u);
        assert.ok(graph.includes('[cv1]trim=start=6:end=9,setpts=PTS-STARTPTS[kv1]'));
        assert.ok(
            graph.includes(
                '[ca0]atrim=start=0:end=4,asetpts=PTS-STARTPTS,afade=t=out:st=3.97:d=0.03[ka0]',
            ),
            'o 1º trecho só tem fade de saída',
        );
        assert.ok(
            graph.includes('[ca1]atrim=start=6:end=9,asetpts=PTS-STARTPTS,afade=t=in:d=0.03[ka1]'),
            'o último só tem fade de entrada',
        );
        assert.ok(graph.includes('[kv0][ka0][kv1][ka1]concat=n=2:v=1:a=1[jv][ja]'));
        assert.ok(
            graph.includes('[jv]split=1[b0];[b0]trim=start=0:end=7,'),
            'o reframe parte do vídeo já cortado',
        );
        assert.ok(
            graph.includes('[ja][x0]amix=inputs=2:normalize=0'),
            'o sfx mistura no áudio cortado',
        );
        assert.match(
            graph,
            /\[mix\]loudnorm=I=-14:TP=-1,aresample=48000,asetpts=N\/SR\/TB\[aout\]$/u,
        );
        console.log(
            'ok  graph: trim/atrim + concat a/v no mesmo graph, fade só nas emendas, loudnorm',
        );
    }

    {
        const many = Array.from({ length: 216 }, (_, index) =>
            vertical(index * 0.1, index % 2 === 0 ? 0.1 : 0.4),
        );
        const timeline = Timeline.fromRemoved(
            22,
            [
                [3, 4],
                [9, 9.6],
                [15, 16],
            ],
            30,
        );
        const graph = new ReframeFilterBuilder().build(
            timeline.keyframes(many),
            '#000000',
            1920,
            1080,
            timeline.duration,
            30,
            null,
            [],
            [],
            timeline,
        );
        const depths = [...graph.matchAll(/x='([^']*)'/gu)].map(
            (match) => match[1]!.split('if(').length - 1,
        );

        assert.ok(
            depths.length >= 3 && depths.every((depth) => depth <= 80),
            `teto de 80 keyframes por trecho vale depois do remap: ${depths.join(',')}`,
        );
        console.log('ok  teto de ~80 keyframes por trecho continua depois do remap');
    }

    {
        const expected = JSON.parse(readFileSync(GOLDEN, 'utf8')) as Record<string, string[]>;

        for (const [name, body] of Object.entries(legacyPayloads)) {
            assert.deepEqual(
                await ffmpegArgs(body),
                expected[name],
                `payload ${name} sem cuts/dead_air mudou os args do ffmpeg`,
            );
        }

        const withCuts = await ffmpegArgs({ ...legacyPayloads['bare'], cuts: [] });
        assert.ok(
            withCuts.includes('[aout]') && !withCuts.includes('copy'),
            'cuts: [] já sai do copy',
        );
        console.log('ok  golden: payload antigo monta os mesmos args, byte a byte');
    }

    {
        const job = enqueued({
            keyframes,
            cuts: [
                [0, 1.5],
                [10.25, 12],
            ],
            dead_air: true,
        });
        assert.deepEqual(
            [job.cuts, job.deadAir],
            [
                [
                    [0, 1.5],
                    [10.25, 12],
                ],
                true,
            ],
        );
        assert.deepEqual(
            [enqueued({ keyframes }).cuts, enqueued({ keyframes }).deadAir],
            [null, false],
            'ausente = sem jump cut',
        );

        refused(
            {
                cuts: [
                    [5, 6],
                    [2, 3],
                ],
            },
            'cuts.1',
        );
        refused(
            {
                cuts: [
                    [2, 6],
                    [5, 8],
                ],
            },
            'cuts.1',
        );
        refused({ cuts: [[-1, 2]] }, 'cuts.0');
        refused({ cuts: [[3, 3]] }, 'cuts.0');
        refused({ cuts: [[1, 'x']] }, 'cuts.0');
        refused({ cuts: [[1, 2, 3]] }, 'cuts.0');
        refused({ cuts: { a: 1 } }, 'cuts');
        refused({ dead_air: 'sim' }, 'dead_air');
        refused(
            { cuts: [], settings: { captions: true }, transcript: { segments: [] } },
            'captions',
        );
        console.log('ok  fronteira: cuts ordenados, sem sobreposição e no formato do PR9');
    }

    if (!ffmpegAvailable()) {
        console.log('--  render real pulado: ffmpeg ausente');
        console.log('\nReframeJumpCutTest: ok');
        return;
    }

    const dir = mkdtempSync(join(tmpdir(), 'reframe-jumpcut-'));

    try {
        ffmpeg(
            dir,
            ...['-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=30:duration=12'],
            ...['-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=12'],
            ...['-af', "volume=enable='between(t,5,6)':volume=0"],
            ...[
                '-c:v',
                'libx264',
                '-preset',
                'ultrafast',
                '-c:a',
                'aac',
                '-shortest',
                'source.mp4',
            ],
        );
        ffmpeg(dir, '-f', 'lavfi', '-i', 'color=red:s=64x64', '-frames:v', '1', 'sticker.png');
        ffmpeg(dir, '-f', 'lavfi', '-i', 'sine=frequency=880:duration=0.2', 'hit.wav');

        const webhooks: WebhookPayload[] = [];
        const files: Record<string, string> = {
            'videos/source.mp4': 'source.mp4',
            'assets/emoji/sticker.png': 'sticker.png',
            'assets/sfx/hit.wav': 'hit.wav',
        };
        const queue = new ReframeQueueService(
            {
                download: (key: string, path: string): Promise<void> => {
                    copyFileSync(join(dir, files[key]!), path);
                    return Promise.resolve();
                },
                uploadFile: (path: string): Promise<void> => {
                    copyFileSync(path, join(dir, 'out.mp4'));
                    return Promise.resolve();
                },
            } as never,
            new Probe(),
            new FfmpegRunner(),
            undefined,
            undefined,
            undefined,
            {
                send: (_url: string, payload: WebhookPayload): Promise<boolean> => {
                    webhooks.push(payload);
                    return Promise.resolve(true);
                },
            } as never,
        );

        await queue['process'](
            enqueued({
                source_key: 'videos/source.mp4',
                keyframes: [vertical(0, 0.2), vertical(4, 0.5)],
                cuts: [
                    [2, 3],
                    [8, 9],
                ],
                dead_air: true,
                captions: [{ t: [1, 2.5], text: 'cruza o corte', style: 'speech' }],
                watermark: '@unkvoid_clips',
                overlays: [{ key: 'assets/emoji/sticker.png', t: [9.5, 10.5], kind: 'emoji' }],
                sfx: [{ key: 'assets/sfx/hit.wav', t: 9.5 }],
            }),
        );

        const expected = 2 + (5.12 - 3) + (8 - 5.88) + 3;
        const durations = streamDurations(join(dir, 'out.mp4'));
        const silences = spawnSync(
            'ffmpeg',
            [
                '-hide_banner',
                '-i',
                'out.mp4',
                '-af',
                'silencedetect=noise=-35dB:d=0.4',
                '-f',
                'null',
                '-',
            ],
            { cwd: dir },
        ).stderr.toString();

        assert.equal(webhooks[0]?.status, 'done', webhooks[0]?.error);
        assert.ok(
            Math.abs(durations['video']! - expected) < 0.1,
            `vídeo com ${String(durations['video'])}s, esperado ~${String(expected)}s (12 − 2 cuts − ar morto)`,
        );
        assert.ok(
            Math.abs(durations['audio']! - durations['video']!) < 0.05,
            `áudio (${String(durations['audio'])}s) e vídeo (${String(durations['video'])}s) com a mesma duração`,
        );
        assert.ok(
            Math.abs(webhooks[0]!.duration_seconds! - durations['video']!) < 0.05,
            'duration_seconds do webhook é a duração final',
        );
        const audioPts = execFileSync('ffprobe', [
            '-v',
            'error',
            '-select_streams',
            'a',
            '-show_entries',
            'packet=pts_time',
            '-of',
            'csv=p=0',
            join(dir, 'out.mp4'),
        ])
            .toString()
            .trim()
            .split('\n')
            .map(Number);
        const holes = audioPts.filter(
            (pts, index) => index > 0 && pts - audioPts[index - 1]! > 1024 / 48000 + 0.001,
        );

        assert.deepEqual(holes, [], 'sem buraco no pts do áudio (o loudnorm cru deixa um)');
        assert.doesNotMatch(
            silences,
            /silence_start/u,
            'a pausa de 1s virou corte (sobram só as folgas de 0.12s)',
        );

        copyFileSync(join(dir, 'source.mp4'), join(dir, 'source'));
        const note = {
            t: [5.2, 5.8] as [number, number],
            text: 'pausa',
            style: 'note' as const,
            pos: 'bottom' as const,
        };
        const speech = { ...note, style: 'speech' as const };
        const memeClip = {
            key: 'assets/meme/a.mp4',
            t: [5.2, 5.8] as [number, number],
            kind: 'meme_clip' as const,
            pos: null,
        };
        const emoji = { ...memeClip, kind: 'emoji' as const };
        assert.equal(
            (await queue['deadAir'](dir, [speech], [emoji])).length,
            1,
            'a pausa de 1s vira corte',
        );
        assert.deepEqual(await queue['deadAir'](dir, [note], []), [], 'pausa com nota fica');
        assert.deepEqual(
            await queue['deadAir'](dir, [speech], [memeClip]),
            [],
            'pausa com meme_clip fica',
        );
        console.log(
            `ok  render real: vídeo ${String(durations['video'])}s, áudio ${String(durations['audio'])}s, webhook ${String(webhooks[0]!.duration_seconds)}s`,
        );
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }

    console.log('\nReframeJumpCutTest: ok');
}

await main();
