/**
 * Criação do navegador + contexto com stealth — porta de _make_stealth_context.
 * Usa playwright-extra com puppeteer-extra-plugin-stealth (mesma stack do
 * antigo login.js) para reduzir a detecção de automação.
 */

import { mkdtemp, readdir, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { BrowserContext } from 'playwright';
import { chromium } from 'playwright-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';

import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';
import { sendDiscordError } from '@/services/notifications/Discord';
import type { BrowserSession } from '@/types/UploadType';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
(chromium as any).use(StealthPlugin());

const USER_AGENT =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
    '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

const LAUNCH_ARGS = [
    '--disable-blink-features=AutomationControlled',
    '--no-sandbox',
    '--disable-infobars',
    '--disable-dev-shm-usage',
];

// Monta a opção de proxy do Playwright a partir das envs. Vazio = sem proxy.
// SOCKS5 no Chromium não suporta auth, então user/senha só entram se preenchidos
// (caso de proxy HTTP). Logamos o servidor (sem credenciais) para rastreabilidade.
function buildProxy(): { server: string; username?: string; password?: string } | undefined {
    const server = settings.proxyServer.trim();
    if (server === '') {
        return undefined;
    }
    const proxy: { server: string; username?: string; password?: string } = { server };
    if (settings.proxyUsername !== '') {
        proxy.username = settings.proxyUsername;
    }
    if (settings.proxyPassword !== '') {
        proxy.password = settings.proxyPassword;
    }
    return proxy;
}

export async function createStealthSession(
    headless: boolean,
    videoDir?: string,
): Promise<BrowserSession> {
    const proxy = buildProxy();
    const usingProxy = proxy !== undefined;

    // Com proxy (IP BR) o fingerprint tem que ser brasileiro, senão IP × timezone ×
    // locale ficam contraditórios e o TikTok desconfia. Ainda dá para sobrescrever.
    const locale = settings.browserLocale || (usingProxy ? 'pt-BR' : 'en-US');
    const timezoneId =
        settings.browserTimezone || (usingProxy ? 'America/Sao_Paulo' : 'America/New_York');

    if (usingProxy) {
        logger.info(`Proxy ativo: ${proxy.server} (locale=${locale}, tz=${timezoneId})`);
    }

    const browser = await chromium.launch({
        headless,
        args: LAUNCH_ARGS,
        ...(proxy ? { proxy } : {}),
    });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        userAgent: USER_AGENT,
        locale,
        timezoneId,
        // Grava a sessão em 854x480 (arquivo menor) para anexar no Discord em
        // caso de erro. O .webm só é finalizado quando o contexto fecha.
        ...(videoDir ? { recordVideo: { dir: videoDir, size: { width: 854, height: 480 } } } : {}),
    });
    return { browser, context };
}

/**
 * Roda uma interação de navegador gravando vídeo. Em erro (captcha, sessão,
 * código...), finaliza a gravação, envia o vídeo ao Discord com a mensagem do
 * erro e apaga o arquivo; depois relança para a fila tratar. Em sucesso, o
 * vídeo é descartado.
 */
export async function runRecordedSession<T>(
    headless: boolean,
    label: string,
    fn: (context: BrowserContext) => Promise<T>,
): Promise<T> {
    const videoDir = await mkdtemp(join(tmpdir(), 'tiktok-video-'));
    const { browser, context } = await createStealthSession(headless, videoDir);
    try {
        return await fn(context);
    } catch (error) {
        await context.close().catch(() => undefined);
        const video = await largestVideo(videoDir);
        await sendDiscordError(label, error, video);
        throw error;
    } finally {
        await browser.close().catch(() => undefined);
        await rm(videoDir, { recursive: true, force: true }).catch(() => undefined);
    }
}

/**
 * O contexto pode ter mais de uma página (ex.: a checagem de IP abre uma rápida),
 * cada uma com seu .webm. O maior arquivo é o da página principal — é esse que
 * interessa anexar no Discord.
 */
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
    } catch {
        return null;
    }
}

/**
 * Descobre e loga o IP público de saída do navegador (passa pelo proxy, se houver).
 * Serve para confirmar que o post está saindo pelo IP brasileiro esperado.
 * Falha de rede aqui é só avisada — não derruba o upload.
 */
export async function logExitIp(context: BrowserSession['context']): Promise<void> {
    const page = await context.newPage();
    try {
        const res = await page.request.get('https://ipinfo.io/json', { timeout: 15000 });
        const info = (await res.json()) as {
            ip?: string;
            city?: string;
            region?: string;
            country?: string;
            org?: string;
        };
        logger.info(
            `IP de saída do post: ${info.ip ?? '?'} — ${info.city ?? '?'}/${info.region ?? '?'} ` +
                `(${info.country ?? '?'}) ${info.org ?? ''}`.trim(),
        );
        if (info.country && info.country !== 'BR') {
            logger.warn(
                `IP de saída NÃO é brasileiro (country=${info.country}). Verifique o proxy/VPN.`,
            );
        }
    } catch (err) {
        logger.warn(`Não foi possível obter o IP de saída: ${(err as Error).message}`);
    } finally {
        await page.close();
    }
}
