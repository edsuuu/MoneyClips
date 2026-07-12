import type { Cookie } from '@/Types/DomainType';

const VALID_SAME_SITE = new Set(['Strict', 'Lax', 'None']);

const SESSION_COOKIE_NAMES = ['sessionid', 'sid_tt', 'sessionid_ss', 'passport_auth_status'];

export function normalizeCookies(cookies: Cookie[]): Cookie[] {
    return cookies.map((cookie) => ({
        ...cookie,
        sameSite: cookie.sameSite && VALID_SAME_SITE.has(cookie.sameSite) ? cookie.sameSite : 'Lax',
    }));
}

export function sessionExpired(cookies: Cookie[], now: number = Date.now() / 1000): boolean {
    const session = cookies.filter((c) => SESSION_COOKIE_NAMES.includes(c.name));
    if (session.length === 0) {
        return true;
    }
    return session.every((c) => typeof c.expires === 'number' && c.expires < now);
}
