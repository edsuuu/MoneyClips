/**
 * Legenda literal do /reframe (estilo gusta): um Dialogue por bloco, sem
 * karaokê, com o texto que o Laravel mandou. Porte do `build_ass` do
 * render.py do protótipo; sem `caption_preset` a saída é a dele.
 *
 * Duas pegadinhas do libass, ambas silenciosas:
 * - A Marca usa a família `Montserrat` (+ negrito + itálico). Com o nome
 *   `Montserrat Bold` do render.py, o libass não acha a BoldItalic e cai na
 *   Helvetica sem erro.
 * - Sombra suave sem contorno (branco_limpo) = contorno transparente + `\blur`.
 *   Com contorno 0 o `\blur` borra a própria letra.
 */

export const CAPTION_PRESETS = ['verde', 'branco_italico', 'branco_limpo'] as const;
export const CAPTION_STYLES = ['speech', 'shout', 'punch', 'aside', 'note', 'art'] as const;
export const CAPTION_POSITIONS = ['bottom', 'top'] as const;

export type CaptionPreset = (typeof CAPTION_PRESETS)[number];
export type CaptionStyle = (typeof CAPTION_STYLES)[number];
export type CaptionPosition = (typeof CAPTION_POSITIONS)[number];

export interface ReframeCaption {
    t: [number, number];
    text: string;
    style: CaptionStyle;
    pos: CaptionPosition;
}

interface CaptionLook {
    color: string;
    italic: string;
    outline: string;
    outlineColor: string;
    shadow: string;
    backColor: string;
    accent: string;
    tags: string;
}

const WHITE = '&H00FFFFFF';
const BLACK = '&H00000000';
const YELLOW = '&H0000E6FF';
const GREEN = '&H0000D400';

const BASE_LOOK: CaptionLook = {
    color: WHITE,
    italic: '0',
    outline: '3',
    outlineColor: BLACK,
    shadow: '4',
    backColor: BLACK,
    accent: YELLOW,
    tags: '',
};

const PRESET_LOOKS: Record<CaptionPreset, CaptionLook> = {
    verde: { ...BASE_LOOK, color: GREEN, italic: '-1' },
    branco_italico: { ...BASE_LOOK, italic: '-1' },
    branco_limpo: {
        ...BASE_LOOK,
        outline: '1',
        outlineColor: '&HFF000000',
        backColor: '&H40000000',
        accent: GREEN,
        tags: '\\blur4',
    },
};

const STYLE_ASS: Record<CaptionStyle, { name: string; wrap: number }> = {
    speech: { name: 'Fala', wrap: 16 },
    shout: { name: 'Grito', wrap: 13 },
    punch: { name: 'Punch', wrap: 9 },
    aside: { name: 'Aparte', wrap: 22 },
    note: { name: 'Nota', wrap: 22 },
    art: { name: 'Arte', wrap: 12 },
};

const CAPTION_Y: Record<CaptionPosition, number> = { bottom: 1360, top: 520 };
const ART_Y = 864;
const WATERMARK_Y = [1490, 1000] as const;
const WATERMARK_EVERY_SECONDS = 40;
const WATERMARK_SECONDS = 5;
const MIN_BLOCK_SECONDS = 0.05;
const PUNCTUATION = /(?<!\d)[,:](?!\d)|[;!]|(?<![.\d])\.(?![.\d])/gu;

export class LiteralSubtitleBuilder {
    public render(
        captions: ReframeCaption[],
        preset: CaptionPreset | null,
        watermark: string,
        duration: number,
    ): string {
        const look = preset === null ? BASE_LOOK : PRESET_LOOKS[preset];
        const events: string[] = [];

        if (watermark !== '') {
            for (let index = 0; index * WATERMARK_EVERY_SECONDS < duration; index += 1) {
                const start = index * WATERMARK_EVERY_SECONDS;
                const end = Math.min(start + WATERMARK_SECONDS, duration);

                events.push(
                    `Dialogue: 0,${this.assTime(start)},${this.assTime(end)},Marca,,0,0,0,,` +
                        `{\\pos(540,${String(WATERMARK_Y[index % 2])})}${this.escape(watermark)}`,
                );
            }
        }

        for (const caption of captions) {
            if (caption.t[1] - caption.t[0] < MIN_BLOCK_SECONDS) {
                continue;
            }

            events.push(...this.dialogues(caption, look));
        }

        return `${this.header(look)}${events.join('\n')}\n`;
    }

    private dialogues(caption: ReframeCaption, look: CaptionLook): string[] {
        const { name, wrap } = STYLE_ASS[caption.style];
        const time = `${this.assTime(caption.t[0])},${this.assTime(caption.t[1])}`;
        const position = `\\pos(540,${String(CAPTION_Y[caption.pos])})`;
        const raw = this.escape(caption.text);

        if (caption.style === 'note') {
            return [`Dialogue: 1,${time},${name},,0,0,0,,{${position}}${this.wrap(raw, wrap)}`];
        }

        const text = this.wrap(raw.replace(PUNCTUATION, ''), wrap);

        if (caption.style === 'punch') {
            return [
                `Dialogue: 1,${time},${name},,0,0,0,,{${position}\\bord9\\blur9\\3c${look.accent}&\\3a&H70&}${text}`,
                `Dialogue: 2,${time},${name},,0,0,0,,{${position}\\blur1}${text}`,
            ];
        }

        if (caption.style === 'art') {
            const entrance = `\\frz8\\t(0,160,\\frz-8)\\move(-300,${String(ART_Y)},540,${String(ART_Y)},0,160)`;

            return [
                `Dialogue: 3,${time},${name},,0,0,0,,{${entrance}\\1a&HFF&\\bord16\\blur12\\3c&H00F020A0&}${text}`,
                `Dialogue: 4,${time},${name},,0,0,0,,{${entrance}}${text}`,
            ];
        }

        return [`Dialogue: 2,${time},${name},,0,0,0,,{${position}${look.tags}}${text}`];
    }

    private header(look: CaptionLook): string {
        const voice = `${look.outlineColor},${look.backColor},-1,${look.italic},0,0,100,100`;

        return [
            '[Script Info]',
            'ScriptType: v4.00+',
            'PlayResX: 1080',
            'PlayResY: 1920',
            'WrapStyle: 2',
            'ScaledBorderAndShadow: yes',
            '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            `Style: Fala,Montserrat ExtraBold,64,${look.color},${look.color},${voice},-1,0,1,${look.outline},${look.shadow},2,90,90,0,1`,
            'Style: Nota,Montserrat SemiBold,46,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,0,-1,0,0,100,100,0,0,1,2,3,2,140,140,0,1',
            'Style: Marca,Montserrat,32,&H8CFFFFFF,&H8CFFFFFF,&H8C000000,&H00000000,-1,-1,0,0,100,100,0,0,1,1.5,0,5,0,0,0,1',
            `Style: Grito,Montserrat Black,77,${look.color},${look.color},${voice},0,0,1,${look.outline},${look.shadow},2,60,60,0,1`,
            `Style: Punch,Montserrat Black,115,${look.accent},${look.accent},${BLACK},${BLACK},-1,${look.italic},0,0,100,100,0,0,1,4,0,2,60,60,0,1`,
            `Style: Aparte,Montserrat ExtraBold,48,${WHITE},${WHITE},${voice},0,0,1,${look.outline},${look.shadow},2,140,140,0,1`,
            `Style: Arte,Montserrat Black,96,${YELLOW},${YELLOW},&H000000FF,${BLACK},-1,0,0,0,100,100,0,0,1,5,0,5,40,40,0,1`,
            '',
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
            '',
        ].join('\n');
    }

    private wrap(text: string, limit: number): string {
        if (text.includes('\\N') || [...text].length <= limit) {
            return text;
        }

        const words = text.trim().split(/\s+/u);
        let best = text;
        let bestWidth = Number.POSITIVE_INFINITY;

        for (let index = 1; index < words.length; index += 1) {
            const top = words.slice(0, index).join(' ');
            const bottom = words.slice(index).join(' ');
            const width = Math.max([...top].length, [...bottom].length);

            if (width < bestWidth) {
                best = `${top}\\N${bottom}`;
                bestWidth = width;
            }
        }

        return best;
    }

    /**
     * Quebra de linha crua (\n, \r\n, \r) vira `\N`: no .ass cada linha é um
     * registro, e uma quebra no texto injetaria Dialogue/Style novos.
     */
    private escape(text: string): string {
        return text
            .replaceAll('{', '(')
            .replaceAll('}', ')')
            .replace(/\r\n?|\n/gu, '\\N');
    }

    private assTime(seconds: number): string {
        const centiseconds = Math.round(Math.max(0, seconds) * 100);
        const hours = Math.floor(centiseconds / 360_000);
        const minutes = Math.floor((centiseconds % 360_000) / 6000);
        const rest = ((centiseconds % 6000) / 100).toFixed(2).padStart(5, '0');

        return `${String(hours)}:${String(minutes).padStart(2, '0')}:${rest}`;
    }
}
