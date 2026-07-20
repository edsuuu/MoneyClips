/**
 * Golden test da legenda: compara o .ass/.srt gerados pelo SubtitleBuilder com
 * a saída do `subtitles.py` do AutoCaption original (arquivos em tests/golden,
 * gerados pelo Python antes da migração).
 *
 * Existe porque legenda quebrada é SILENCIOSA: um timestamp nulo tratado
 * diferente, um agrupamento de linha que atravessa segmento, e o vídeo sai com
 * a legenda fora de sincronia sem nenhum erro no log. A fixture tem de propósito
 * `start` nulo, `end` nulo, palavra só com pontuação, palavra em branco, estouro
 * de 3 palavras e estouro de 2.5s.
 *
 *   pnpm test
 */

import { strict as assert } from 'node:assert';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { SubtitleBuilder, type SubtitleGeometry } from '@/Services/Caption/SubtitleBuilder';

const GOLDEN_DIR = join(dirname(fileURLToPath(import.meta.url)), 'golden');

const CASES: { name: string; geometry: SubtitleGeometry }[] = [
    { name: 'orig', geometry: { width: 1920, height: 1080, fontScale: 1, alignment: 2 } },
    {
        name: 'below',
        geometry: {
            width: 1080,
            height: 1920,
            fontScale: 1.9,
            alignment: 8,
            marginVOverride: 1500,
        },
    },
    {
        name: 'vert',
        geometry: { width: 1080, height: 1920, fontScale: 1.5, alignment: 2, marginVOverride: 300 },
    },
];

async function main(): Promise<void> {
    const transcript = JSON.parse(
        readFileSync(join(GOLDEN_DIR, 'transcript.json'), 'utf8'),
    ) as Parameters<SubtitleBuilder['build']>[0];

    const workDir = mkdtempSync(join(tmpdir(), 'subs-golden-'));
    const builder = new SubtitleBuilder();

    try {
        for (const { name, geometry } of CASES) {
            const srt = join(workDir, `${name}.srt`);
            const ass = join(workDir, `${name}.ass`);

            await builder.build(transcript, srt, ass, geometry);

            for (const [kind, produced] of [
                ['ass', ass],
                ['srt', srt],
            ] as const) {
                assert.equal(
                    readFileSync(produced, 'utf8'),
                    readFileSync(join(GOLDEN_DIR, `${name}.${kind}`), 'utf8'),
                    `${name}.${kind} divergiu do golden do AutoCaption`,
                );
                console.log(`ok  ${name}.${kind}`);
            }
        }

        console.log('\nlegenda idêntica ao pipeline original.');
    } finally {
        rmSync(workDir, { recursive: true, force: true });
    }
}

await main();
