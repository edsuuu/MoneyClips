/**
 * Check da cor de legenda por locutor. Três coisas quebram em silêncio aqui:
 * o transcript ANTIGO (sem `speaker`) tem que sair byte a byte igual ao de
 * antes do recurso, o hex web precisa virar `&HBBGGRR&` (ordem invertida no
 * .ass — trocar isso pinta a legenda de outra cor sem erro nenhum) e o locutor
 * sem entrada no mapa tem que cair na cor padrão em vez de gerar tag inválida.
 *
 *   pnpm test
 */

import { strict as assert } from 'node:assert';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { ReframeController } from '@/Http/Controllers/ReframeController';
import {
    SubtitleBuilder,
    type SubtitleGeometry,
    type Transcript,
} from '@/Services/Caption/SubtitleBuilder';
import { type ReframeJob, ReframeQueueService } from '@/Services/Reframe/ReframeQueueService';

const SPEAKER_ONE_ASS = '&H0066D1FF&';
const SPEAKER_TWO_ASS = '&H00F0C94C&';

const transcript = (
    firstSpeaker?: number | string,
    secondSpeaker?: number | string,
): Transcript => ({
    segments: [
        {
            start: 0,
            end: 1,
            ...(firstSpeaker === undefined ? {} : { speaker: firstSpeaker }),
            words: [
                { word: 'oi', start: 0, end: 0.5 },
                { word: 'tudo', start: 0.5, end: 1 },
            ],
        },
        {
            start: 1,
            end: 2,
            ...(secondSpeaker === undefined ? {} : { speaker: secondSpeaker }),
            words: [
                { word: 'bem', start: 1, end: 1.5 },
                { word: 'sim', start: 1.5, end: 2 },
            ],
        },
    ],
});

const geometry = (speakerColors?: Record<string, string>): SubtitleGeometry => ({
    width: 1080,
    height: 1920,
    fontScale: 1.5,
    primaryColor: '&H00FFFFFF',
    ...(speakerColors === undefined ? {} : { speakerColors }),
    textTransform: 'none',
});

async function buildAss(
    workDir: string,
    name: string,
    input: Transcript,
    subtitleGeometry: SubtitleGeometry,
): Promise<string> {
    const ass = join(workDir, `${name}.ass`);
    await new SubtitleBuilder().build(input, join(workDir, `${name}.srt`), ass, subtitleGeometry);

    return readFileSync(ass, 'utf8');
}

async function buildReframeAss(
    workDir: string,
    speakerColors: Record<string, string>,
): Promise<string> {
    const queue = new ReframeQueueService();
    const job: ReframeJob = {
        uuid: 'job-uuid',
        editUuid: 'edit-uuid',
        sourceKey: 'videos/source.mp4',
        outputKey: 'videos/out.mp4',
        duration: 2,
        keyframes: [],
        settings: {
            background: '#000000',
            captions: true,
            captionColor: '#ffffff',
            captionCase: 'sentence',
            speakerColors,
        },
        transcript: transcript(1, 2),
        webhookUrl: 'http://127.0.0.1:8000/api/webhook/reframe',
    };

    await queue['buildSubtitles'](job, workDir);

    return readFileSync(join(workDir, 'subs.ass'), 'utf8');
}

function capturedSettings(rawSettings: unknown): ReframeJob['settings'] {
    let captured: ReframeJob | null = null;
    const queue = {
        enqueue: (job: ReframeJob): string => {
            captured = job;

            return job.uuid;
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
                settings: rawSettings,
            },
        } as never,
        { status: () => ({ json: () => undefined }) } as never,
    );

    assert.notEqual(captured, null, 'o controller precisa enfileirar o job');

    return (captured as unknown as ReframeJob).settings;
}

async function main(): Promise<void> {
    const workDir = mkdtempSync(join(tmpdir(), 'subs-speaker-'));

    try {
        const legacy = await buildAss(workDir, 'legacy', transcript(), geometry());
        const withSpeakersNoMap = await buildAss(workDir, 'nomap', transcript(1, 2), geometry());
        const unknownSpeaker = await buildAss(
            workDir,
            'unknown',
            transcript(7, 7),
            geometry({ '1': SPEAKER_ONE_ASS }),
        );

        assert.equal(
            withSpeakersNoMap,
            legacy,
            'transcript com speaker mas sem mapa tem que sair igual ao legado',
        );
        assert.equal(
            unknownSpeaker,
            legacy,
            'locutor sem entrada no mapa cai no captionColor, sem tag extra',
        );
        assert.doesNotMatch(
            legacy,
            /\{\\c&H00FFFFFF&\}[A-Za-zÀ-ú]/u,
            'sem locutor nenhuma tag de cor primária é emitida',
        );
        console.log('ok  retrocompatível: sem speaker e speaker sem cor saem idênticos ao legado');

        const colored = await buildReframeAss(workDir, { '1': '#ffd166', '2': '#4cc9f0' });

        assert.ok(
            colored.includes(`{\\c${SPEAKER_ONE_ASS}}oi`),
            '#ffd166 vira &H0066D1FF& na primeira palavra do locutor 1',
        );
        assert.ok(
            colored.includes(`{\\c${SPEAKER_TWO_ASS}}bem`),
            '#4cc9f0 vira &H00F0C94C& na primeira palavra do locutor 2',
        );
        assert.ok(
            colored.indexOf(SPEAKER_ONE_ASS) < colored.indexOf(SPEAKER_TWO_ASS),
            'a cor do locutor 1 aparece antes da do locutor 2',
        );
        assert.match(
            colored,
            /Style: Default,[^\n]*,&H00FFFFFF,/u,
            'o estilo continua com a cor padrão da legenda',
        );
        console.log('ok  duas cores distintas, em &HBBGGRR& e na ordem dos segmentos');

        const valid = capturedSettings({
            captions: true,
            captionColor: '#ffffff',
            speakerColors: { '1': '#ffd166', '2': '#4cc9f0' },
        });
        assert.deepEqual(valid.speakerColors, { '1': '#ffd166', '2': '#4cc9f0' });

        const dirty = capturedSettings({
            speakerColors: { '1': 'vermelho', '2': 123, '3': '#4cc9f0', '4': '#fff' },
        });
        assert.deepEqual(dirty.speakerColors, { '3': '#4cc9f0' }, 'só #rrggbb sobrevive');

        assert.deepEqual(capturedSettings({ speakerColors: ['#ffd166'] }).speakerColors, {});
        assert.deepEqual(capturedSettings({}).speakerColors, {});
        console.log('ok  fronteira: speakerColors inválido vira {} sem derrubar a request');

        console.log('\nSubtitleSpeakerTest: ok');
    } finally {
        rmSync(workDir, { recursive: true, force: true });
    }
}

await main();
