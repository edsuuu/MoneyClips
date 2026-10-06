/**
 * Check da legenda literal do /reframe (`captions[]`). Tudo aqui quebra em
 * silêncio, porque o vídeo sai sem erro nenhum no log:
 *
 * - Paridade com o render.py do protótipo: o spec 08 (125 blocos + marca
 *   d'água) sem preset e com `verde` tem que sair igual ao `build_ass` dele
 *   (golden gerado pelo próprio render.py). Duas diferenças de propósito: os
 *   estilos novos no cabeçalho e a fonte da Marca, que no render.py caía na
 *   Helvetica.
 * - Golden por preset com um bloco de cada estilo.
 * - Fronteira: payload antigo continua no karaokê; payload novo inválido vira
 *   422 no enqueue.
 * - As fontes vão junto pro jobDir, senão o libass troca por outra.
 *
 *   pnpm test
 */

import { strict as assert } from 'node:assert';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { ValidationError } from '@/Exceptions/ValidationError';
import { ReframeController } from '@/Http/Controllers/ReframeController';
import { LiteralSubtitleBuilder } from '@/Services/Reframe/LiteralSubtitleBuilder';
import { type ReframeJob, ReframeQueueService } from '@/Services/Reframe/ReframeQueueService';

const GOLDEN_DIR = join(dirname(fileURLToPath(import.meta.url)), 'golden');
const NEW_STYLES = /^Style: (Grito|Punch|Aparte|Arte),.*\n/gmu;

interface Fixture {
    duration: number;
    watermark: string;
    captions: unknown[];
}

const golden = (name: string): string => readFileSync(join(GOLDEN_DIR, name), 'utf8');
const fixture = (name: string): Fixture => JSON.parse(golden(name)) as Fixture;

function enqueued(extra: Record<string, unknown>): ReframeJob {
    let captured: ReframeJob | null = null;
    const queue = {
        enqueue: (job: ReframeJob): string => {
            captured = job;

            return 'job-uuid';
        },
    } as unknown as ReframeQueueService;

    new ReframeController(queue).create(
        {
            body: {
                edit_uuid: 'edit-uuid',
                source_key: 'videos/source.mp4',
                output_key: 'videos/out.mp4',
                keyframes: [{ t: 0, mode: 'vertical', regions: [] }],
                webhook_url: 'http://127.0.0.1:8000/api/webhook/reframe',
                ...extra,
            },
        } as never,
        { status: () => ({ json: () => undefined }) } as never,
    );

    assert.notEqual(captured, null, 'o controller precisa enfileirar o job');

    return captured as unknown as ReframeJob;
}

function rejected(extra: Record<string, unknown>): string[] {
    try {
        enqueued(extra);
    } catch (error) {
        assert.ok(error instanceof ValidationError, 'erro de payload tem que ser 422');

        return Object.keys(error.details as Record<string, string>);
    }

    assert.fail(`payload deveria ser recusado: ${JSON.stringify(extra)}`);
}

function rendered(name: string, preset: string | null): string {
    const input = fixture(name);
    const job = enqueued({
        captions: input.captions,
        watermark: input.watermark,
        ...(preset === null ? {} : { caption_preset: preset }),
    });

    assert.notEqual(job.captions, null);

    return new LiteralSubtitleBuilder().render(
        job.captions ?? [],
        job.captionPreset,
        job.watermark,
        input.duration,
    );
}

async function main(): Promise<void> {
    for (const [preset, color] of [
        [null, 'white'],
        ['verde', 'green'],
    ] as const) {
        const expected = golden(`literal-renderpy-${color}.ass`).replace(
            'Style: Marca,Montserrat Bold,',
            'Style: Marca,Montserrat,',
        );

        assert.equal(
            rendered('literal-spec08.json', preset).replace(NEW_STYLES, ''),
            expected,
            `spec 08 (${color}) divergiu do build_ass do render.py`,
        );
        console.log(`ok  paridade com o render.py: spec 08 ${color}`);
    }

    for (const preset of ['verde', 'branco_italico', 'branco_limpo']) {
        assert.equal(
            rendered('literal-styles.json', preset),
            golden(`literal-${preset}.ass`),
            `literal-${preset}.ass divergiu do golden`,
        );
        console.log(`ok  golden ${preset}: speech, shout, punch, aside, note, art, top, marca`);
    }

    const legacy = enqueued({ settings: { captions: true } });
    assert.equal(legacy.captions, null, 'sem captions[] continua o karaokê');
    assert.equal(legacy.captionPreset, null);
    assert.equal(legacy.watermark, '');

    const defaults = enqueued({ captions: [{ t: [1, 2], text: 'oi' }] });
    assert.deepEqual(defaults.captions, [
        { t: [1, 2], text: 'oi', style: 'speech', pos: 'bottom' },
    ]);
    assert.deepEqual(enqueued({ captions: [] }).captions, [], 'lista vazia ainda é modo literal');
    console.log('ok  fronteira: payload antigo no karaokê, defaults speech/bottom');

    assert.deepEqual(rejected({ captions: 'oi' }), ['captions']);
    assert.deepEqual(rejected({ captions: [{ t: [2, 1], text: 'oi' }] }), ['captions.0.t']);
    assert.deepEqual(rejected({ captions: [{ t: [1], text: 'oi' }] }), ['captions.0.t']);
    assert.deepEqual(rejected({ captions: [{ t: ['1', 2], text: 'oi' }] }), ['captions.0.t']);
    assert.deepEqual(rejected({ captions: [{ t: [null, true], text: 'oi' }] }), ['captions.0.t']);
    assert.deepEqual(rejected({ captions: [{ t: [1, 2], text: ' ' }] }), ['captions.0.text']);
    assert.deepEqual(rejected({ captions: [{ t: [1, 2], text: 'oi', style: 'meme' }] }), [
        'captions.0.style',
    ]);
    assert.deepEqual(rejected({ captions: [{ t: [1, 2], text: 'oi', pos: 'middle' }] }), [
        'captions.0.pos',
    ]);
    assert.deepEqual(rejected({ captions: [], caption_preset: 'amarelo' }), ['caption_preset']);
    assert.deepEqual(rejected({ caption_preset: 'verde' }), ['captions']);
    assert.deepEqual(rejected({ watermark: '@canal' }), ['captions']);
    console.log('ok  fronteira: bloco, preset e marca inválidos viram 422');

    const injected = new LiteralSubtitleBuilder().render(
        [
            {
                t: [0, 1],
                text: 'oi\rDialogue: 9,0:00:00.00,0:00:09.00,Fala,,0,0,0,,X',
                style: 'note',
                pos: 'top',
            },
        ],
        null,
        '@canal\r\nStyle: Fala,Arial\nDialogue: 9,0:00:00.00,0:00:09.00,Fala,,0,0,0,,X',
        10,
    );
    assert.doesNotMatch(
        injected,
        /\r|^(Dialogue: 9|Style: Fala,Arial)/mu,
        'quebra no texto não vira registro',
    );
    assert.match(
        injected,
        /\}@canal\\NStyle: Fala,Arial\\NDialogue: 9/u,
        'a quebra vira \\N na própria linha',
    );
    console.log(
        'ok  quebra de linha (\\n, \\r\\n, \\r) no texto e na marca vira \\N, sem injetar evento',
    );

    const numbers = new LiteralSubtitleBuilder().render(
        [
            {
                t: [0, 1],
                text: 'ganhou 1,5 milhão às 10:30 por R$ 2.500',
                style: 'speech',
                pos: 'bottom',
            },
        ],
        null,
        '',
        10,
    );
    assert.match(
        numbers,
        /ganhou 1,5 milhão às\\N10:30 por R\$ 2\.500/u,
        'pontuação entre dígitos fica',
    );
    console.log('ok  pontuação entre dígitos (1,5 · 10:30 · 2.500) não é apagada');

    const workDir = mkdtempSync(join(tmpdir(), 'literal-subs-'));

    try {
        const job = enqueued({ captions: [{ t: [0, 1], text: 'oi' }], watermark: '@canal' });
        const ass = await new ReframeQueueService()['buildLiteralSubtitles'](
            job,
            job.captions ?? [],
            workDir,
            10,
        );

        assert.equal(ass, 'captions.ass:fontsdir=fonts', 'o filtro ass recebe o fontsdir relativo');
        assert.match(readFileSync(join(workDir, 'captions.ass'), 'utf8'), /Marca.*@canal\n/u);

        for (const font of [
            'Black',
            'BlackItalic',
            'BoldItalic',
            'ExtraBold',
            'ExtraBoldItalic',
            'SemiBoldItalic',
        ]) {
            assert.ok(
                existsSync(join(workDir, 'fonts', `Montserrat-${font}.ttf`)),
                `Montserrat-${font}.ttf precisa ir pro jobDir`,
            );
        }
        console.log('ok  jobDir: captions.ass + as 6 Montserrat');
    } finally {
        rmSync(workDir, { recursive: true, force: true });
    }

    console.log('\nLiteralSubtitleTest: ok');
}

await main();
