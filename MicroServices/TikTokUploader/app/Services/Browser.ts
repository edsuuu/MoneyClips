import { mkdtemp, readdir, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { BrowserContext } from 'playwright';
import { chromium } from 'playwright-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';

import { discord } from '@/Services/Notifications/Discord';
import type { BrowserSession } from '@/Types/UploadType';

(chromium as unknown as { use: (plugin: unknown) => void }).use(StealthPlugin());

const USER_AGENT =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
    '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

const LAUNCH_ARGS = [
    '--disable-blink-features=AutomationControlled',
    '--no-sandbox',
    '--disable-infobars',
    '--disable-dev-shm-usage',
    // WSL não tem GPU/compositor estável — sem isso o render trava.
    '--disable-gpu',
];

export async function createStealthSession(
    headless: boolean,
    videoDir?: string,
): Promise<BrowserSession> {
    const browser = await chromium.launch({ headless, args: LAUNCH_ARGS });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        userAgent: USER_AGENT,
        locale: 'pt-BR',
        timezoneId: 'America/Sao_Paulo',
        ...(videoDir ? { recordVideo: { dir: videoDir, size: { width: 854, height: 480 } } } : {}),
    });
    return { browser, context };
}

export async function runRecordedSession<T>(
    headless: boolean,
    label: string,
    fn: (context: BrowserContext) => Promise<T>,
    keepOpen = false,
): Promise<T> {
    const videoDir = await mkdtemp(join(tmpdir(), 'tiktok-video-'));
    const { browser, context } = await createStealthSession(headless, videoDir);

    try {
        const result = await fn(context);

        // ponytail: keepOpen deixa o Chromium aberto (login manual/debug) e vaza o
        // processo até o restart — só pra dev, nunca em produção headless.
        if (keepOpen) {
            return result;
        }

        await browser.close().catch(() => undefined);
        await rm(videoDir, { recursive: true, force: true }).catch(() => undefined);

        return result;
    } catch (error) {
        // ponytail: keepOpen deixa o navegador aberto até no erro (login manual/debug) e
        // vaza o processo até o restart — sem vídeo no Discord (só finaliza ao fechar).
        if (keepOpen) {
            throw error;
        }

        await context.close().catch(() => undefined);
        const video = await largestVideo(videoDir);
        await discord.notifyError(label, error, video);
        await browser.close().catch(() => undefined);
        await rm(videoDir, { recursive: true, force: true }).catch(() => undefined);

        throw error;
    }
}

async function largestVideo(dir: string): Promise<string | null> {
    try {
        const webms = (await readdir(dir)).filter((name) => name.endsWith('.webm'));
        let biggest: { path: string; size: number } | null = null;
        for (const name of webms) {
            const path = join(dir, name);
            const { size } = await stat(path);
            if (!biggest || size > biggest.size) {
                biggest = { path, size };
            }
        }
        return biggest?.path ?? null;
    } catch (error) {
        console.warn(`[WARN] falha ao localizar o vídeo gravado em ${dir}`, error);

        return null;
    }
}
