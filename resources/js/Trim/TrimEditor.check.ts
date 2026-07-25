import { TrimEditor } from './TrimEditor.ts';

// Roda com `node resources/js/Trim/TrimEditor.check.ts` (type stripping nativo).
function assert(condition: boolean, message: string): void {
    if (!condition) {
        throw new Error(`TrimEditor.check: ${message}`);
    }
}

const editor = new TrimEditor({
    duration: 600,
    storyboard: { url: 'sb.jpg', cols: 10, rows: 13, interval: 40, tileWidth: 160, tileHeight: 90 },
});

assert(editor.a === 0 && editor.b === 180, 'estado inicial clampa no corte máximo (3 min)');

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
assert(editor.a === 65 && editor.b === 180, 'início só mexe no início (fim intacto)');
assert(Math.round(editor.aPercent * 100) / 100 === 10.83, 'percentual do handle A');

editor.applyEnd('1:00:00');
assert(editor.b === 245 && editor.a === 65, 'fim trava em início + 3 min, início intacto');

editor.applyStart('00:00');
assert(editor.a === 65, 'início trava em fim - 3 min');

editor.applyEnd('00:30');
assert(editor.b === 66, 'fim antes do início trava em início + 1');

editor.applyStart('lixo');
assert(editor.a === 65, 'input inválido não altera o ponto');

const stepper = new TrimEditor({
    duration: 600,
    storyboard: { url: 'sb.jpg', cols: 10, rows: 13, interval: 40, tileWidth: 160, tileHeight: 90 },
});
stepper.$dispatch = () => undefined;
stepper.applyStart('00:10');
stepper.applyEnd('01:00');
stepper.step('a', 1);
assert(stepper.a === 11, 'seta soma 1s no início');
stepper.step('b', -1);
assert(stepper.b === 59, 'seta tira 1s no fim');
stepper.applyStart('00:00');
stepper.applyEnd('03:00');
stepper.step('b', 1);
assert(
    stepper.b === 180 && stepper.a === 0,
    'seta no fim respeita o cap de 3 min sem mexer no início',
);

const mover = new TrimEditor({
    duration: 600,
    storyboard: { url: 'sb.jpg', cols: 10, rows: 13, interval: 40, tileWidth: 160, tileHeight: 90 },
});
mover.$dispatch = () => undefined;
mover.$refs = {
    strip: { getBoundingClientRect: () => ({ left: 0, width: 600 }) } as unknown as HTMLElement,
};
mover.startDrag('window', {
    clientX: 0,
    pointerId: 1,
    buttons: 1,
    target: { setPointerCapture: () => undefined },
} as unknown as PointerEvent);
mover.onDrag({ clientX: 500, pointerId: 1, buttons: 1 } as unknown as PointerEvent);
assert(
    mover.a === 420 && mover.b === 600,
    'arrastar o meio reposiciona mantendo a duração e trava no fim',
);

assert(editor.timecode(65) === '01:05' && editor.timecode(3661) === '1:01:01', 'timecode');
assert(editor.sanitizeTime('a-1:3x0') === '1:30', 'sanitize remove tudo que não é dígito ou :');
