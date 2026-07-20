/**
 * Geração das legendas karaokê (.ass) e do .srt de apoio.
 *
 * Porte fiel do `subtitles.py` do AutoCaption. Dois detalhes NÃO são cosméticos:
 *
 * 1. O faster-whisper emite `start`/`end` nulos em palavras. O preenchimento
 *    (herda o fim da anterior; procura o início da próxima; piso de 50ms) é o
 *    que impede evento de duração zero e legenda dessincronizada.
 * 2. O agrupamento em linhas roda POR SEGMENTO — uma linha nunca atravessa
 *    segmentos, e o fallback do `start` nulo enxerga só as palavras do segmento
 *    corrente. Agrupar globalmente muda o texto na tela.
 *
 * O karaokê não usa tags `\k`: cada palavra vira um Dialogue que redesenha a
 * linha inteira, e o fim de um evento é o início do próximo (destaque sem
 * buraco). As palavras futuras ficam com alpha FF — invisíveis, mas ainda
 * ocupando largura, que é o que impede a linha de "pular" ao trocar de palavra.
 */

import { writeFile } from 'node:fs/promises';

import { settings } from '@/Config/Env';

const WHITE = '&H00FFFFFF';
const REFERENCE_WIDTH = 1080;
const REFERENCE_HEIGHT = 1920;

export interface TranscriptWord {
    word?: string | null;
    start?: number | null;
    end?: number | null;
}

export interface TranscriptSegment {
    start?: number | null;
    end?: number | null;
    words?: TranscriptWord[] | null;
}

export interface Transcript {
    segments?: TranscriptSegment[] | null;
}

export interface SubtitleGeometry {
    width: number;
    height: number;
    fontScale?: number;
    alignment?: number;
    marginVOverride?: number | null;
    offset?: number | null;
}

interface Word {
    text: string;
    start: number;
    end: number;
}

interface Line {
    words: Word[];
}

export class SubtitleBuilder {
    public async build(
        transcript: Transcript,
        srtOut: string,
        assOut: string,
        geometry: SubtitleGeometry,
    ): Promise<void> {
        const lines = this.buildLines(transcript);

        await writeFile(srtOut, this.renderSrt(lines), 'utf8');
        await writeFile(assOut, this.renderAss(lines, geometry), 'utf8');
    }

    /** Agrupa por segmento — uma linha nunca atravessa a fronteira de segmento. */
    private buildLines(transcript: Transcript): Line[] {
        const lines: Line[] = [];

        for (const segment of transcript.segments ?? []) {
            let current: Word[] = [];

            for (const word of this.collectWords({ segments: [segment] })) {
                const tooManyWords = current.length >= settings.maxWordsPerLine;
                const tooLong =
                    current[0] !== undefined &&
                    word.end - current[0].start > settings.maxLineDuration;

                if (current.length > 0 && (tooManyWords || tooLong)) {
                    lines.push({ words: current });
                    current = [];
                }

                current.push(word);
            }

            if (current.length > 0) {
                lines.push({ words: current });
            }
        }

        return lines;
    }

    private collectWords(transcript: Transcript): Word[] {
        const raw: { word: TranscriptWord; segStart: number; segEnd: number }[] = [];

        for (const segment of transcript.segments ?? []) {
            const segStart = Number(segment.start ?? 0);
            const segEnd = Number(segment.end ?? segStart);

            for (const word of segment.words ?? []) {
                raw.push({ word, segStart, segEnd });
            }
        }

        const result: Word[] = [];

        for (const [index, item] of raw.entries()) {
            const text = this.normalizeToken(item.word.word ?? '');
            if (text === '') {
                continue;
            }

            const start = this.resolveStart(item.word, result, item.segStart);
            const end = this.resolveEnd(item.word, raw, index, item.segEnd);

            result.push({ text, start, end: Math.max(end, start + 0.05) });
        }

        return result;
    }

    private normalizeToken(word: string): string {
        return String(word)
            .trim()
            .replace(/^[,.;:!?\-–— ]+/u, '');
    }

    private resolveStart(word: TranscriptWord, collected: Word[], segStart: number): number {
        if (word.start !== null && word.start !== undefined) {
            return Number(word.start);
        }

        return collected.at(-1)?.end ?? segStart;
    }

    private resolveEnd(
        word: TranscriptWord,
        raw: { word: TranscriptWord }[],
        index: number,
        segEnd: number,
    ): number {
        if (word.end !== null && word.end !== undefined) {
            return Number(word.end);
        }

        for (const next of raw.slice(index + 1)) {
            if (next.word.start !== null && next.word.start !== undefined) {
                return Number(next.word.start);
            }
        }

        return segEnd;
    }

    private renderSrt(lines: Line[]): string {
        const blocks = lines.map((line, index) => {
            const start = this.formatSrtTime(line.words[0]?.start ?? 0);
            const end = this.formatSrtTime(line.words.at(-1)?.end ?? 0);
            const text = line.words
                .map((word) => word.text)
                .join(' ')
                .toUpperCase();

            return `${String(index + 1)}\n${start} --> ${end}\n${text}\n`;
        });

        return blocks.join('\n');
    }

    private renderAss(lines: Line[], geometry: SubtitleGeometry): string {
        const offset = geometry.offset ?? settings.subtitleOffset;
        const events: string[] = [];

        for (const line of lines) {
            for (const [index, word] of line.words.entries()) {
                const start = Math.max(0, word.start + offset);
                const rawEnd = line.words[index + 1]?.start ?? word.end;
                const end = Math.max(Math.max(0, rawEnd + offset), start + 0.05);

                events.push(
                    `Dialogue: 0,${this.formatAssTime(start)},${this.formatAssTime(end)},` +
                        `Default,,0,0,0,,${this.highlightLine(line, index)}`,
                );
            }
        }

        return `${this.assHeader(geometry) + events.join('\n')}\n`;
    }

    private highlightLine(line: Line, activeIndex: number): string {
        const highlight = this.colorTag(settings.highlightColor);
        const white = this.colorTag(WHITE);

        return line.words
            .map((word, index) => {
                const token = word.text.toUpperCase();

                if (index === activeIndex) {
                    return `{\\c${highlight}}${token}{\\c${white}}`;
                }

                if (settings.hideFutureWords && index > activeIndex) {
                    return `{\\alpha&HFF&}${token}{\\alpha&H00&}`;
                }

                return token;
            })
            .join(' ');
    }

    private assHeader(geometry: SubtitleGeometry): string {
        const { width, height } = geometry;
        const fontScale = geometry.fontScale ?? 1;
        const alignment = geometry.alignment ?? 2;

        const scaleV = height / REFERENCE_HEIGHT;
        const scaleH = width / REFERENCE_WIDTH;

        const fontSize = Math.max(12, Math.round(settings.fontSize * 4 * scaleV * fontScale));
        const outline = Math.max(1, Math.round(4 * scaleV * fontScale));
        const shadow = Math.max(0, Math.round(2 * scaleV));
        const marginV = geometry.marginVOverride ?? Math.max(10, Math.round(180 * scaleV));
        const marginLr = Math.max(10, Math.round(40 * scaleH));
        const highlight = settings.highlightColor.trim().replace(/&+$/u, '');

        return `[Script Info]
ScriptType: v4.00+
PlayResX: ${String(width)}
PlayResY: ${String(height)}
WrapStyle: 0
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Default,${settings.fontName},${String(fontSize)},${WHITE},${highlight},&H00000000,&H64000000,-1,-1,0,0,100,100,0,0,1,${String(outline)},${String(shadow)},${String(alignment)},${String(marginLr)},${String(marginLr)},${String(marginV)},1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
`;
    }

    private colorTag(color: string): string {
        const trimmed = color.trim();

        return trimmed.endsWith('&') ? trimmed : `${trimmed}&`;
    }

    private formatSrtTime(seconds: number): string {
        let ms = Math.round(seconds * 1000);
        const hours = Math.floor(ms / 3_600_000);
        ms -= hours * 3_600_000;
        const minutes = Math.floor(ms / 60_000);
        ms -= minutes * 60_000;
        const secs = Math.floor(ms / 1000);
        ms -= secs * 1000;

        return `${this.pad(hours)}:${this.pad(minutes)}:${this.pad(secs)},${String(ms).padStart(3, '0')}`;
    }

    private formatAssTime(seconds: number): string {
        let cs = Math.round(seconds * 100);
        const hours = Math.floor(cs / 360_000);
        cs -= hours * 360_000;
        const minutes = Math.floor(cs / 6000);
        cs -= minutes * 6000;
        const secs = Math.floor(cs / 100);
        cs -= secs * 100;

        return `${String(hours)}:${this.pad(minutes)}:${this.pad(secs)}.${this.pad(cs)}`;
    }

    private pad(value: number): string {
        return String(value).padStart(2, '0');
    }
}
