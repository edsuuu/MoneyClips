/** Carga, normalização e validação dos cookies de sessão do TikTok. */

import { access, mkdir, readFile, rename, rm, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

import type { SessionView } from '@/types/ApiType';
import type { Cookie } from '@/types/DomainType';
import { COOKIES_DIR } from '@/utils/Paths';

const VALID_SAME_SITE = new Set(['Strict', 'Lax', 'None']);

/** Cookies que representam a sessão logada — se todos expiraram, precisa relogar. */
const SESSION_COOKIE_NAMES = ['sessionid', 'sid_tt', 'sessionid_ss', 'passport_auth_status'];

/** Caminho do arquivo de cookies de uma conta. */
export function cookieFile(accountName: string): string {
    return join(COOKIES_DIR, `${accountName}.json`);
}

/** Normaliza o campo sameSite para um valor aceito pelo Playwright. Função pura. */
export function normalizeCookies(cookies: Cookie[]): Cookie[] {
    return cookies.map((cookie) => ({
        ...cookie,
        sameSite: cookie.sameSite && VALID_SAME_SITE.has(cookie.sameSite) ? cookie.sameSite : 'Lax',
    }));
}

/**
 * True se TODOS os cookies de sessão já expiraram (logo, a sessão é inválida).
 * Porta de check_expiry do function.py. Função pura.
 */
export function sessionExpired(cookies: Cookie[], now: number = Date.now() / 1000): boolean {
    const session = cookies.filter((c) => SESSION_COOKIE_NAMES.includes(c.name));
    if (session.length === 0) {
        return true;
    }
    return session.every((c) => typeof c.expires === 'number' && c.expires < now);
}

export async function hasCookies(accountName: string): Promise<boolean> {
    try {
        await access(cookieFile(accountName));
        return true;
    } catch {
        return false;
    }
}

export async function readCookies(accountName: string): Promise<Cookie[]> {
    const raw = await readFile(cookieFile(accountName), 'utf-8');
    return normalizeCookies(JSON.parse(raw) as Cookie[]);
}

export async function saveCookies(accountName: string, cookies: Cookie[]): Promise<void> {
    await ensureCookiesDir();
    // Escreve em arquivo temporário e renomeia (atômico no mesmo FS) para um
    // GET /session ou job concorrente nunca ler um JSON pela metade.
    const file = cookieFile(accountName);
    const tmp = `${file}.tmp`;
    await writeFile(tmp, JSON.stringify(cookies, null, 2));
    await rename(tmp, file);
}

export async function deleteCookies(accountName: string): Promise<void> {
    await rm(cookieFile(accountName), { force: true });
}

export async function ensureCookiesDir(): Promise<void> {
    await mkdir(COOKIES_DIR, { recursive: true });
}

/**
 * Checagem leve (sem abrir o navegador) usada pelo GET /session: olha se há
 * cookie salvo e se a sessão ainda não expirou. Não garante que o TikTok
 * aceitará a sessão — só descarta o óbvio (sem cookie / expirado).
 */
export async function checkSession(accountName: string): Promise<SessionView> {
    if (!(await hasCookies(accountName))) {
        return { account: accountName, has_cookies: false, expired: true, valid: false };
    }
    const expired = sessionExpired(await readCookies(accountName));
    return { account: accountName, has_cookies: true, expired, valid: !expired };
}
