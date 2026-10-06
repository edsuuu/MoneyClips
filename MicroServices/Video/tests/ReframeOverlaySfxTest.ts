/**
 * Check de overlays[] e sfx[] no /reframe. O que quebra em silêncio: sem os
 * campos a saída tem que ser a antiga byte a byte; a figurinha precisa de
 * input com janela + eof_action=pass (o default congela até o fim); o amix
 * sem normalize=0 derruba a voz; a numeração dos inputs tem que casar entre
 * inputArgs() e o graph; e key fora de assets/ não pode chegar ao MinIO.
 *
 *   pnpm test
 */

import { strict as assert } from 'node:assert';

import { ValidationError } from '@/Exceptions/ValidationError';
import { ReframeController } from '@/Http/Controllers/ReframeController';
import {
    ReframeFilterBuilder,
    type ReframeKeyframe,
    type ReframeOverlay,
    type ReframeSfx,
} from '@/Services/Reframe/ReframeFilterBuilder';
import type { ReframeJob, ReframeQueueService } from '@/Services/Reframe/ReframeQueueService';

const builder = new ReframeFilterBuilder();

const keyframes: ReframeKeyframe[] = [
    { t: 0, mode: 'vertical', regions: [{ x: 0.2, y: 0, w: 0.3164, h: 1 }] },
];

const graph = (assFile: string | null, overlays: ReframeOverlay[], sfx: ReframeSfx[]): string =>
    builder.build(keyframes, '#000000', 1920, 1080, 90, 30, assFile, overlays, sfx);

{
    assert.equal(
        graph('subs.ass', [], []),
        builder.build(keyframes, '#000000', 1920, 1080, 90, 30, 'subs.ass'),
        'sem overlays/sfx o graph é o antigo, byte a byte',
    );
    assert.deepEqual(builder.inputArgs([], []), [], 'sem overlays/sfx nenhum input extra');
    assert.doesNotMatch(graph('subs.ass', [], []), /\[aout\]/u, 'sem sfx o áudio segue copy');
    console.log('ok  0 elementos: graph e inputs idênticos ao antigo');
}

{
    const overlays: ReframeOverlay[] = [
        { key: 'assets/emoji/pleading.png', t: [3, 4.5], kind: 'small', pos: null },
    ];
    const sfx: ReframeSfx[] = [{ key: 'assets/sfx/hit.wav', t: 12.04, gainDb: 0 }];
    const result = graph('subs.ass', overlays, sfx);

    assert.deepEqual(builder.inputArgs(overlays, sfx), [
        '-loop',
        '1',
        '-framerate',
        '30',
        '-t',
        '1.5',
        '-i',
        'assets/emoji/pleading.png',
        '-i',
        'assets/sfx/hit.wav',
    ]);
    assert.ok(result.includes('[r0v]concat=n=1:v=1:a=0[vc]'), 'o concat sai num label');
    assert.ok(
        result.includes(
            '[1:v]fps=30,format=rgba,scale=w=200:h=200:force_original_aspect_ratio=decrease,setpts=PTS-STARTPTS+3/TB[o0]',
        ),
        'a janela começa em a via setpts',
    );
    assert.ok(
        result.includes('[vc][o0]overlay=x=540-overlay_w/2:y=1190-overlay_h:eof_action=pass[v0]'),
        'small fica colado acima da legenda e sai no fim da janela',
    );
    assert.ok(result.includes('[v0]null,ass=subs.ass[vout]'), 'a legenda fica por cima');
    assert.ok(
        result.includes('[2:a]volume=0dB,adelay=12040:all=1[x0]'),
        'o sfx atrasa até t em ms',
    );
    assert.ok(
        result.includes(
            '[0:a][x0]amix=inputs=2:normalize=0:duration=first,alimiter=limit=0.97:level=0[aout]',
        ),
        'amix sem normalizar e limiter sem auto-ganho',
    );
    assert.doesNotMatch(result, /enable=/u, 'nada de input do clip inteiro com enable');
    console.log('ok  1 elemento: overlay com janela, sfx no mix e legenda por cima');
}

{
    const overlays: ReframeOverlay[] = [
        { key: 'assets/wiki/linea.jpg', t: [1, 2.2], kind: 'card', pos: { x: 0.5, y: 0.3 } },
        { key: 'assets/memes/7.gif', t: [5, 6], kind: 'meme', pos: null },
        { key: 'assets/memes/39.mp4', t: [8, 10.5], kind: 'meme_clip', pos: null },
    ];
    const sfx: ReframeSfx[] = [
        { key: 'assets/sfx/whoosh.wav', t: 1, gainDb: -4 },
        { key: 'assets/memes/39.mp4', t: 8, gainDb: 0 },
        { key: 'assets/sfx/whoosh.wav', t: 5, gainDb: 2.5 },
    ];
    const result = graph(null, overlays, sfx);

    assert.deepEqual(builder.inputArgs(overlays, sfx), [
        ...['-loop', '1', '-framerate', '30', '-t', '1.2', '-i', 'assets/wiki/linea.jpg'],
        ...['-ignore_loop', '0', '-t', '1', '-i', 'assets/memes/7.gif'],
        ...['-t', '2.5', '-i', 'assets/memes/39.mp4'],
        ...['-i', 'assets/sfx/whoosh.wav', '-i', 'assets/memes/39.mp4'],
    ]);
    assert.equal(result.match(/eof_action=pass/gu)?.length, 3, 'uma janela por aparição');
    assert.ok(
        result.includes(
            'force_original_aspect_ratio=decrease,pad=w=iw+32:h=ih+32:x=16:y=16:color=white',
        ),
        'card ganha a borda branca',
    );
    assert.ok(
        result.includes('[vc][o0]overlay=x=540-overlay_w/2:y=576-overlay_h/2:eof_action=pass[v0]'),
        'pos é o centro normalizado',
    );
    assert.ok(
        result.includes(
            'pad=w=1080:h=1920:x=(ow-iw)/2:y=(oh-ih)/2:color=black,setpts=PTS-STARTPTS+8/TB[o2]',
        ),
        'meme_clip vira tela cheia com pad preto',
    );
    assert.ok(result.includes('[v1][o2]overlay=x=0:y=0:eof_action=pass[v2]'));
    assert.ok(result.includes('[v2]null[vout]'), 'sem legenda o último overlay vira o [vout]');
    assert.ok(result.includes('[4:a]volume=-4dB,adelay=1000:all=1[x0]'));
    assert.ok(result.includes('[5:a]volume=0dB,adelay=8000:all=1[x1]'), 'o áudio do meme_clip');
    assert.ok(
        result.includes('[4:a]volume=2.5dB,adelay=5000:all=1[x2]'),
        'a mesma key reusa o input',
    );
    assert.ok(result.includes('[0:a][x0][x1][x2]amix=inputs=4:normalize=0'));
    console.log('ok  N elementos: cadeia encadeada, inputs numerados e key de sfx reusada');
}

const enqueued = (body: Record<string, unknown>): ReframeJob => {
    let captured: ReframeJob | null = null;
    const queue = {
        enqueue: (job: ReframeJob): string => {
            captured = job;

            return 'uuid';
        },
    } as unknown as ReframeQueueService;

    new ReframeController(queue).create(
        {
            body: {
                edit_uuid: 'edit-uuid',
                source_key: 'videos/source.mp4',
                output_key: 'videos/out.mp4',
                keyframes,
                webhook_url: 'http://127.0.0.1:8000/api/webhook/reframe',
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
        () => enqueued(body),
        (error: unknown) =>
            error instanceof ValidationError &&
            Object.keys(error.details as Record<string, string>).includes(field),
        `esperava 422 em ${field}`,
    );
};

{
    const legacy = enqueued({});
    assert.deepEqual(
        [legacy.overlays, legacy.sfx],
        [[], []],
        'campos ausentes viram listas vazias',
    );

    const job = enqueued({
        overlays: [{ key: 'assets/emoji/pleading.png', t: [3, 4.5], kind: 'emoji' }],
        sfx: [{ key: 'assets/sfx/hit.wav', t: 12.04 }],
    });
    assert.deepEqual(job.overlays, [
        { key: 'assets/emoji/pleading.png', t: [3, 4.5], kind: 'emoji', pos: null },
    ]);
    assert.deepEqual(job.sfx, [{ key: 'assets/sfx/hit.wav', t: 12.04, gainDb: 0 }]);

    const image = { t: [1, 2], kind: 'small' };
    refused({ overlays: [{ ...image, key: 'videos/x/cut.png' }] }, 'overlays.0.key');
    refused({ overlays: [{ ...image, key: 'assets/../videos/x.png' }] }, 'overlays.0.key');
    refused({ overlays: [{ ...image, key: '/assets/x.png' }] }, 'overlays.0.key');
    refused({ sfx: [{ key: 'uploads/x.wav', t: 1 }] }, 'sfx.0.key');
    refused(
        { overlays: [{ key: 'assets/memes/1.png', t: [1, 2], kind: 'meme_clip' }] },
        'overlays.0.kind',
    );
    refused(
        { overlays: [{ key: 'assets/memes/39.mp4', t: [1, 2], kind: 'meme' }] },
        'overlays.0.kind',
    );
    refused({ overlays: [{ ...image, key: 'assets/x.png', t: [2, 2] }] }, 'overlays.0.t');
    refused(
        { overlays: [{ ...image, key: 'assets/x.png', pos: { x: 1.2, y: 0.5 } }] },
        'overlays.0.pos',
    );
    refused({ sfx: [{ key: 'assets/x.wav', t: 1, gain_db: '3' }] }, 'sfx.0.gain_db');
    refused({ sfx: [{ key: 'assets/x.wav', t: 1, gain_db: 200 }] }, 'sfx.0.gain_db');
    refused(
        { overlays: Array.from({ length: 41 }, () => ({ ...image, key: 'assets/x.png' })) },
        'overlays',
    );
    refused({ sfx: Array.from({ length: 41 }, () => ({ key: 'assets/x.wav', t: 1 })) }, 'sfx');
    console.log('ok  fronteira: só assets/, kind casa com o arquivo e teto de 40 por lista');
}

console.log('\nReframeOverlaySfxTest: ok');
