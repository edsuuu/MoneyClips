/**
 * Check do ReframeFilterBuilder: o filtergraph é uma string que o ffmpeg só
 * valida em runtime — um label errado ou uma expressão quebrada viram job
 * failed silencioso na fila. Cobre: expressão constante (1 keyframe), lerp
 * piecewise (2 keyframes), trecho por modo com concat, vstack do split e a cor
 * de fundo do modo centrado.
 *
 *   pnpm test
 */

import { strict as assert } from 'node:assert';

import {
    ReframeFilterBuilder,
    type ReframeKeyframe,
} from '@/Services/Reframe/ReframeFilterBuilder';

const BACKGROUND = '#112233';

const builder = new ReframeFilterBuilder();

const region = (
    x: number,
    y: number,
    w: number,
    h: number,
): { x: number; y: number; w: number; h: number } => ({ x, y, w, h });

{
    const keyframes: ReframeKeyframe[] = [
        { t: 0, mode: 'vertical', regions: [region(0.2, 0, 0.3164, 1)] },
    ];
    const graph = builder.build(keyframes, BACKGROUND, 1920, 1080, 90, 30, null);

    assert.match(
        graph,
        /^\[0:v\]fps=30,split=1\[b0\]/,
        'começa normalizando fps e dividindo por trecho',
    );
    assert.match(
        graph,
        /trim=start=0:end=90,setpts=PTS-STARTPTS/,
        'trecho único cobre o clip inteiro',
    );
    assert.match(
        graph,
        /pad=w=1920:h=3414:x=0:y=0:color=black/,
        'pad até a proporção 9:16 do slot',
    );
    assert.match(
        graph,
        /zoompan=z='1920\/\(607\.488\)':x='384':y='0':d=1:fps=30:s=1080x1920/,
        'keyframe único vira expressão constante',
    );
    assert.match(graph, /\[r0v\]concat=n=1:v=1:a=0\[vout\]$/, 'termina no [vout]');
    assert.doesNotMatch(graph, /if\(/, 'sem keyframe pra interpolar não há if()');
}

{
    const keyframes: ReframeKeyframe[] = [
        { t: 0, mode: 'vertical', regions: [region(0, 0, 0.3164, 1)] },
        { t: 10, mode: 'vertical', regions: [region(0.5, 0, 0.3164, 1)] },
    ];
    const graph = builder.build(keyframes, BACKGROUND, 1920, 1080, 90, 30, null);

    assert.match(
        graph,
        /x='if\(lt\(\(in\/30\),0\),0,if\(lt\(\(in\/30\),10\),0\+\(960-0\)\*\(\(in\/30\)-0\)\/10,960\)\)'/,
        'x interpola 0→960px entre t=0 e t=10 e segura depois',
    );
}

{
    const keyframes: ReframeKeyframe[] = [
        { t: 0, mode: 'vertical', regions: [region(0, 0, 0.3164, 1)] },
        {
            t: 30,
            mode: 'split',
            regions: [region(0, 0, 0.5, 0.4444), region(0.5, 0.5, 0.5, 0.4444)],
        },
    ];
    const graph = builder.build(keyframes, BACKGROUND, 1920, 1080, 90, 30, null);

    assert.match(graph, /split=2\[b0\]\[b1\]/, 'dois trechos de modo');
    assert.match(graph, /\[b1\]trim=start=30:end=90/, 'o segundo trecho começa na troca de modo');
    assert.match(graph, /vstack=inputs=2\[r1v\]/, 'split empilha os dois slots');
    assert.match(graph, /s=1080x960/, 'slot do split tem meia altura');
    assert.match(graph, /\[r0v\]\[r1v\]concat=n=2:v=1:a=0\[vout\]$/, 'concat junta os trechos');
}

{
    const keyframes: ReframeKeyframe[] = [
        { t: 0, mode: 'centered', regions: [region(0.1, 0.1, 0.8, 0.8)] },
    ];
    const graph = builder.build(keyframes, BACKGROUND, 1920, 1080, 90, 30, null);

    assert.match(
        graph,
        /s=1080x608,pad=w=1080:h=1920:x=0:y=656:color=0x112233/,
        'centrado escala contain e pinta as barras',
    );
}

{
    const keyframes: ReframeKeyframe[] = [
        { t: 0, mode: 'vertical', regions: [region(0.2, 0, 0.3164, 1)] },
    ];
    const graph = builder.build(keyframes, BACKGROUND, 1920, 1080, 90, 30, 'subs.ass');

    assert.match(
        graph,
        /concat=n=1:v=1:a=0,ass=subs\.ass\[vout\]$/,
        'legenda entra depois do concat',
    );
}

{
    const keyframes: ReframeKeyframe[] = Array.from({ length: 216 }, (_, index) => ({
        t: index * 0.1,
        mode: 'vertical',
        regions: [region(index % 2 === 0 ? 0.1 : 0.4, 0, 0.3164, 1)],
    }));
    const graph = builder.build(keyframes, BACKGROUND, 1920, 1080, 22, 30, null);
    const depths = [...graph.matchAll(/x='([^']*)'/gu)].map((match) => {
        let depth = 0;
        let deepest = 0;

        for (const char of match[1]!) {
            if (char === '(') {
                depth += 1;
                deepest = Math.max(deepest, depth);
            }

            if (char === ')') {
                depth -= 1;
            }
        }

        return deepest;
    });

    assert.match(graph, /split=3\[b0\]\[b1\]\[b2\]/, '216 keyframes viram 3 pedaços');
    assert.match(graph, /\[b0\]trim=start=0:end=7\.9,/, 'o 1º pedaço vai até o 80º keyframe');
    assert.match(
        graph,
        /\[b1\]trim=start=7\.9:end=15\.8,setpts=PTS-STARTPTS,pad=[^,]+,zoompan=z='[^']+':x='if\(lt\(\(in\/30\),0\),768,/,
        'o 2º pedaço começa no último keyframe do 1º (x=0.4 → 768px)',
    );
    assert.match(graph, /\[b2\]trim=start=15\.8:end=22,/, 'o último pedaço vai até o fim');
    assert.match(graph, /concat=n=3:v=1:a=0\[vout\]$/, 'o concat costura os pedaços');
    assert.ok(
        depths.every((depth) => depth <= 90),
        `aninhamento até 90 (o ffmpeg 8.1 recusa a partir de 97): ${depths.join(',')}`,
    );
}

console.log('ReframeFilterTest: ok');
