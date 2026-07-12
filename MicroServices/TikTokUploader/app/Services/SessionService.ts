import type { Page } from 'playwright';

import { settings } from '@/Config/Env';
import { logger } from '@/Config/Logger';
import * as Selectors from '@/Constants/Selectors';
import { LoginFailedError } from '@/Exceptions/LoginFailedError';
import { runRecordedSession } from '@/Services/Browser';
import { CaptchaSolver } from '@/Services/TikTok/Captcha/CaptchaSolver';
import { resolveCaptcha } from '@/Services/TikTok/Captcha/ResolveCaptcha';
import { normalizeCookies, sessionExpired } from '@/Services/TikTok/Cookies';
import type { LoginView, SessionView } from '@/Types/ApiType';
import type { Cookie } from '@/Types/DomainType';
import { sleep } from '@/Utils/Sleep';

const EMAIL_LOGIN_TIMEOUT_MS = 20_000;

const QR_LOGIN_TIMEOUT_MS = 120_000;

export class SessionService {
    public constructor(private readonly solver: CaptchaSolver = new CaptchaSolver()) {}

    public async checkCookies(cookies: Cookie[]): Promise<SessionView> {
        const account = await runRecordedSession(
            !settings.showBrowser,
            'validar sessão TikTok',
            async (context) => {
                await context.addCookies(normalizeCookies(cookies));

                const page = await context.newPage();

                await page.goto(Selectors.SESSION_CHECK_URL);

                return this.captureHandle(page);
            },
        );

        const valid = account !== null;

        return {
            account: account ?? '',
            has_cookies: cookies.length > 0,
            expired: !valid,
            valid,
        };
    }

    public async login(email: string, password: string, keepOpen: boolean): Promise<LoginView> {
        const result = await runRecordedSession(
            !settings.showBrowser,
            'login no TikTok',
            async (context) => {
                const page = await context.newPage();

                const loggedIn = await this.tryEmailLogin(page, email, password);

                if (!loggedIn) {
                    throw new LoginFailedError(
                        email,
                        'Login por email/senha não concluiu (credenciais ou captcha).',
                    );
                }

                const handle = (await this.captureHandle(page)) ?? email;

                const cookies = (await context.cookies()) as Cookie[];

                const expired = sessionExpired(cookies);

                return { account: handle, cookies, expired, valid: !expired };
            },
            keepOpen,
        );

        return result;
    }

    public async loginWithQrCode(keepOpen: boolean): Promise<LoginView> {
        const result = await runRecordedSession(
            false,
            'login por QR Code no TikTok',
            async (context) => {
                const page = await context.newPage();

                const scanned = await this.tryQrLogin(page);

                if (!scanned) {
                    throw new LoginFailedError(
                        'qr-code',
                        'Login por QR Code não concluiu (QR não escaneado a tempo).',
                    );
                }

                const handle = (await this.captureHandle(page)) ?? 'qr-code';

                const cookies = (await context.cookies()) as Cookie[];

                const expired = sessionExpired(cookies);

                return { account: handle, cookies, expired, valid: !expired };
            },
            keepOpen,
        );

        return result;
    }

    private async tryQrLogin(page: Page): Promise<boolean> {
        try {
            await page.goto(Selectors.QR_LOGIN_URL);

            return await this.waitForLogin(page, 'qr-code', QR_LOGIN_TIMEOUT_MS);
        } catch (error) {
            if (error instanceof LoginFailedError) {
                throw error;
            }

            logger.warn(
                `Falha no login por QR Code: ${error instanceof Error ? error.message : error}`,
            );

            return false;
        }
    }

    private async captureHandle(page: Page): Promise<string | null> {
        const href = await page
            .locator(Selectors.NAV_PROFILE_LINK)
            .first()
            .getAttribute('href', { timeout: 5000 })
            .catch((error: unknown) => {
                logger.warn(
                    `captureHandle: link de perfil não encontrado (${error instanceof Error ? error.message : String(error)})`,
                );

                return null;
            });

        const handle = href?.match(/^\/@([^/?#]+)/)?.[1] ?? null;

        return handle && handle.trim() !== '' ? handle.trim() : null;
    }

    private async tryEmailLogin(page: Page, email: string, password: string): Promise<boolean> {
        try {
            await page.goto(Selectors.LOGIN_EMAIL_URL);
            await page.waitForSelector(Selectors.LOGIN_EMAIL_INPUT, { timeout: 15_000 });

            await page.fill(Selectors.LOGIN_EMAIL_INPUT, email);
            await page.fill(Selectors.LOGIN_PASSWORD_INPUT, password);
            await page.click(Selectors.LOGIN_SUBMIT_BUTTON);

            return await this.waitForLogin(page, email, EMAIL_LOGIN_TIMEOUT_MS);
        } catch (error) {
            if (error instanceof LoginFailedError) {
                throw error;
            }

            logger.warn(
                `Falha no login por email/senha: ${error instanceof Error ? error.message : error}`,
            );

            return false;
        }
    }

    private async waitForLogin(page: Page, email: string, timeoutMs: number): Promise<boolean> {
        const deadline = Date.now() + timeoutMs;

        while (Date.now() < deadline) {
            if (Selectors.LOGGED_IN_URL_PATTERN.test(page.url())) {
                return true;
            }

            const error = await this.readLoginError(page);

            if (error) {
                throw new LoginFailedError(email, `TikTok recusou o login: ${error}`);
            }

            if (await this.hasCaptcha(page)) {
                await resolveCaptcha(page, this.solver);
                await sleep(1000);
                continue;
            }

            await sleep(500);
        }

        return false;
    }

    private async readLoginError(page: Page): Promise<string | null> {
        for (const snippet of Selectors.LOGIN_ERROR_SNIPPETS) {
            const locator = page.getByText(snippet, { exact: false }).first();

            if (await locator.isVisible().catch(() => false)) {
                return (await locator.textContent().catch(() => null))?.trim() || snippet;
            }
        }

        return null;
    }

    private async hasCaptcha(page: Page): Promise<boolean> {
        return (
            (await page.locator(Selectors.CAPTCHA_CONTAINER).isVisible()) ||
            (await page.locator(Selectors.CAPTCHA_QUESTION).isVisible())
        );
    }
}
