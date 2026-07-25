import { TrimEditor } from './TrimEditor.ts';

// Roda com `node resources/js/Trim/trim-editor.check.ts` (type stripping nativo).
function assert(condition: boolean, message: string): void {
    if (!condition) {
        throw new Error(`trim-editor.check: ${message}`);
    }
}

const editor = new TrimEditor({
    duration: 600,
    storyboard: { url: 'sb.jpg', cols: 10, rows: 13, interval: 40, tileWidth: 160, tileHeight: 90 },
});

assert(editor.a === 0 && editor.b === 600, 'estado inicial cobre o vídeo inteiro');

const tiles = editor.tiles;
assert(tiles.length === 16, 'renderiza 16 tiles');
assert(
    tiles[0]!.includes('background-position:0% 0%'),
    'centro do 1º tile (18.75s) cai no índice 0',
);
assert(tiles[0]!.includes('background-size:1000% 1300%'), 'sprite escalado pela grade');
assert(
    tiles[15]!.includes('background-position:44.44444444444444% 8.333333333333332%'),
    'último tile (581.25s → índice 14) vira col 4 / row 1',
);

const degenerate = new TrimEditor({
    duration: 600,
    storyboard: { url: 'sb.jpg', cols: 10, rows: 13, interval: 0, tileWidth: 160, tileHeight: 90 },
});
assert(
    degenerate.tiles.every((tile) => !tile.includes('NaN') && !tile.includes('Infinity')),
    'interval 0 cai no fallback duração/total sem NaN/Infinity',
);

editor.applyStart('01:05');
assert(editor.a === 65, 'input mm:ss vira segundos');
editor.applyEnd('1:00:00');
assert(editor.b === 600, 'h:mm:ss além da duração clampa no fim');
editor.applyEnd('00:30');
assert(editor.b === 66, 'fim antes do início clampa em início + 1');
editor.applyStart('lixo');
assert(editor.a === 65, 'input inválido não altera o ponto');
editor.applyStart('99:99');
assert(editor.a === 65, 'início clampa em fim - 1');

assert(editor.timecode(65) === '01:05' && editor.timecode(3661) === '1:01:01', 'timecode');
assert(Math.round(editor.aPercent * 100) / 100 === 10.83, 'percentual do handle A');
