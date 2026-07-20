/**
 * PNGs estáticos que o ffmpeg consome como input do template: a moldura
 * (fundo + logo + nome/@handle), a marca d'água, a máscara de cantos
 * arredondados e a logo sorteada quando o canal não tem uma.
 *
 * O AutoCaption fazia isto com PIL; aqui é SVG renderizado pelo sharp — mesma
 * geometria, e SVG dá canto arredondado, gradiente e alpha de graça.
 *
 * ponytail: a fonte é resolvida por NOME de família (fontconfig), não por
 * caminho de TTF como no Python — o librsvg embutido no sharp não carrega
 * arquivo solto. Se a família não existir no host, o render cai na fonte
 * padrão do sistema em vez de falhar. Upgrade: registrar a fonte no
 * fontconfig do host (o mesmo que o libass já exige pra FONT_NAME).
 */

import { mkdir } from 'node:fs/promises';
import { dirname } from 'node:path';
import sharp from 'sharp';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';

export const CANVAS_WIDTH = 1080;
export const CANVAS_HEIGHT = 1920;
export const CORNER_RADIUS = 34;

const LOGO_X = 110;
const LOGO_Y = 170;
const LOGO_SIZE = 350;
const LOGO_PAD = 18;
const LOGO_BACKING_RADIUS = 28;
const TEXT_X = 535;
const NAME_Y = 250;
const NAME_SIZE = 62;
const HANDLE_GAP = 20;
const HANDLE_SIZE = 42;

const WATERMARK_ALPHA = 110;
const WATERMARK_SIZE_RATIO = 0.075;

const BACKGROUND: Record<string, string> = { white: '#ffffff', black: '#000000' };
const FOREGROUND: Record<string, string> = { white: '#111111', black: '#ffffff' };
const MUTED: Record<string, string> = { white: '#6e6e73', black: '#aaaaaf' };

export class StaticLayerRenderer extends Logger {
    public async renderLayer(
        background: string,
        outPng: string,
        logoPath: string,
        name: string,
        handle: string,
    ): Promise<string> {
        const logo = await sharp(logoPath)
            .resize(LOGO_SIZE, LOGO_SIZE, { fit: 'cover', position: 'left top' })
            .png()
            .toBuffer();

        const backing =
            background === 'white'
                ? `<rect x="${String(LOGO_X - LOGO_PAD)}" y="${String(LOGO_Y - LOGO_PAD)}" ` +
                  `width="${String(LOGO_SIZE + 2 * LOGO_PAD)}" height="${String(LOGO_SIZE + 2 * LOGO_PAD)}" ` +
                  `rx="${String(LOGO_BACKING_RADIUS)}" fill="#000000"/>`
                : '';

        // dominant-baseline=hanging alinha pelo topo, como o anchor padrão do
        // PIL — é o que mantém o @handle logo abaixo do nome.
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${String(CANVAS_WIDTH)}" height="${String(CANVAS_HEIGHT)}">
  <rect width="100%" height="100%" fill="${BACKGROUND[background] ?? '#ffffff'}"/>
  ${backing}
  <text x="${String(TEXT_X)}" y="${String(NAME_Y)}" dominant-baseline="hanging"
        font-family="${this.escape(settings.templateFontFamily)}" font-size="${String(NAME_SIZE)}"
        font-weight="bold" fill="${FOREGROUND[background] ?? '#111111'}">${this.escape(name)}</text>
  <text x="${String(TEXT_X)}" y="${String(NAME_Y + NAME_SIZE + HANDLE_GAP)}" dominant-baseline="hanging"
        font-family="${this.escape(settings.templateFontFamily)}" font-size="${String(HANDLE_SIZE)}"
        font-weight="bold" fill="${MUTED[background] ?? '#6e6e73'}">${this.escape(handle)}</text>
</svg>`;

        await mkdir(dirname(outPng), { recursive: true });
        await sharp(Buffer.from(svg))
            .composite([{ input: logo, left: LOGO_X, top: LOGO_Y }])
            .png()
            .toFile(outPng);

        return outPng;
    }

    public async renderWatermark(
        text: string,
        regionWidth: number,
        outPng: string,
    ): Promise<string> {
        const size = Math.max(28, Math.round(regionWidth * WATERMARK_SIZE_RATIO));
        const pad = Math.round(size * 0.25);
        const stroke = Math.max(1, Math.floor(size / 28));
        const width = Math.round(text.length * size * 0.62) + 2 * pad;
        const height = size + 2 * pad;
        const alpha = (WATERMARK_ALPHA / 255).toFixed(3);
        const strokeAlpha = ((WATERMARK_ALPHA * 0.55) / 255).toFixed(3);

        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${String(width)}" height="${String(height)}">
  <text x="50%" y="${String(pad)}" dominant-baseline="hanging" text-anchor="middle"
        font-family="${this.escape(settings.templateFontFamily)}" font-size="${String(size)}" font-weight="bold"
        fill="#ffffff" fill-opacity="${alpha}"
        stroke="#000000" stroke-opacity="${strokeAlpha}" stroke-width="${String(stroke)}"
        paint-order="stroke">${this.escape(text)}</text>
</svg>`;

        await mkdir(dirname(outPng), { recursive: true });
        await sharp(Buffer.from(svg)).png().toFile(outPng);

        return outPng;
    }

    public async renderRoundedMask(
        width: number,
        height: number,
        radius: number,
        outPng: string,
    ): Promise<string> {
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${String(width)}" height="${String(height)}">
  <rect width="${String(width)}" height="${String(height)}" rx="${String(radius)}" fill="#ffffff"/>
</svg>`;

        await mkdir(dirname(outPng), { recursive: true });
        await sharp(Buffer.from(svg)).greyscale().png().toFile(outPng);

        return outPng;
    }

    /** Só gera se o canal não tiver logo — círculo com gradiente e a inicial. */
    public async ensureLogo(path: string, initial: string, size = 320): Promise<string> {
        if (await this.exists(path)) {
            return path;
        }

        const first = this.randomChannel(40, 200);
        const second = this.randomChannel(40, 220);
        const radius = size / 2;

        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${String(size)}" height="${String(size)}">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="${first}"/>
      <stop offset="100%" stop-color="${second}"/>
    </linearGradient>
  </defs>
  <circle cx="${String(radius)}" cy="${String(radius)}" r="${String(radius - 4)}" fill="url(#g)"
          stroke="#ffffff" stroke-opacity="0.9" stroke-width="8"/>
  <text x="50%" y="50%" text-anchor="middle" dominant-baseline="central"
        font-family="${this.escape(settings.templateFontFamily)}" font-size="${String(Math.round(size * 0.5))}"
        font-weight="bold" fill="#ffffff">${this.escape(initial)}</text>
</svg>`;

        await mkdir(dirname(path), { recursive: true });
        await sharp(Buffer.from(svg)).png().toFile(path);
        this.info(`[Caption] Logo aleatória gerada: ${path}`);

        return path;
    }

    private randomChannel(min: number, max: number): string {
        const channel = (): number => min + Math.floor(Math.random() * (max - min + 1));

        return `rgb(${String(channel())},${String(channel())},${String(channel())})`;
    }

    private async exists(path: string): Promise<boolean> {
        try {
            await sharp(path).metadata();

            return true;
        } catch {
            return false;
        }
    }

    private escape(value: string): string {
        return value
            .replace(/&/gu, '&amp;')
            .replace(/</gu, '&lt;')
            .replace(/>/gu, '&gt;')
            .replace(/"/gu, '&quot;');
    }
}
