/**
 * Autenticação no TikTok.
 *
 * Estratégia ao garantir uma sessão (`ensureSession`):
 *   1. Se existem cookies salvos e a sessão NÃO expirou -> reutiliza.
 *   2. Se existem mas expiraram -> apaga o arquivo e tenta login novo.
 *   3. Login novo: tenta email/senha em /login/phone-or-email/email. Se não
 *      concluir, avisa no Discord e lança LoginFailedError — o microserviço é
 *      headless, então NÃO há fallback de QR Code (ninguém escaneia num servidor).
 *      Nesse caso, faça login localmente e copie o cookie para o servidor.
 */

import type { Page } from 'playwright';

import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';
import { runRecordedSession } from '@/services/tiktok/Browser';
import { CaptchaSolver } from '@/services/tiktok/captcha/CaptchaSolver';
import { resolveCaptcha } from '@/services/tiktok/captcha/ResolveCaptcha';
import {
    CAPTCHA_CONTAINER,
    CAPTCHA_QUESTION,
    LOGGED_IN_URL_PATTERN,
    LOGIN_EMAIL_INPUT,
    LOGIN_EMAIL_URL,
    LOGIN_PASSWORD_INPUT,
    LOGIN_SUBMIT_BUTTON,
} from '@/services/tiktok/Selectors';
import type { Cookie } from '@/types/DomainType';
import { sleep } from '@/utils/Sleep';

import { deleteCookies, hasCookies, readCookies, saveCookies, sessionExpired } from './Cookies';

const EMAIL_LOGIN_TIMEOUT_MS = 20_000;

/**
 * Login automático não concluído. A fila usa `instanceof` para marcar
 * `login_failed` no callback do webhook (e o Laravel saber que o problema foi o
 * login, não o download ou a publicação).
 */
export class LoginFailedError extends Error {
    public constructor(accountName: string, reason: string) {
        super(`Login automático falhou para '${accountName}': ${reason}`);
        this.name = 'LoginFailedError';
    }
}

export class TikTokAuth {
    private readonly email: string;
    private readonly password: string;
    private readonly solver: CaptchaSolver;

    public constructor(
        email: string = settings.tiktokEmail,
        password: string = settings.tiktokPassword,
        solver: CaptchaSolver = new CaptchaSolver(),
    ) {
        this.email = email;
        this.password = password;
        this.solver = solver;
    }

    /** Garante uma sessão válida e devolve os cookies prontos para o contexto. */
    public async ensureSession(accountName: string): Promise<Cookie[]> {
        if (await hasCookies(accountName)) {
            const cookies = await readCookies(accountName);
            if (!sessionExpired(cookies)) {
                logger.info(`Cookies válidos para '${accountName}' — reutilizando sessão.`);
                return cookies;
            }
            logger.warn(`Cookies de '${accountName}' expiraram — apagando e refazendo login.`);
            await deleteCookies(accountName);
        } else {
            logger.warn(`Sem cookies para '${accountName}' — fazendo login.`);
        }

        await this.loginAndSave(accountName);
        return readCookies(accountName);
    }

    /**
     * Login explícito (módulo de auth, disparado pelo Laravel via POST /login).
     * Diferente de ensureSession: aqui o login é o objetivo, não um efeito
     * colateral de postar. `force` apaga os cookies atuais antes; `headless`
     * sobrescreve o modo do navegador (default: o do .env — true no container).
     */
    public async login(
        accountName: string,
        options: { force?: boolean | undefined; headless?: boolean | undefined } = {},
    ): Promise<void> {
        if (options.force && (await hasCookies(accountName))) {
            logger.info(`Login forçado para '${accountName}' — apagando cookies atuais.`);
            await deleteCookies(accountName);
        }
        await this.loginAndSave(accountName, options.headless ?? settings.headless);
    }

    private async loginAndSave(accountName: string, headless = settings.headless): Promise<void> {
        // Default segue HEADLESS do .env: no container/servidor (sem X server)
        // tem que ser headless, senão o Chromium morre com "Missing X server".
        // Local (HEADLESS=false) abre o navegador para resolver captcha. A sessão
        // é gravada: se o login falhar, runRecordedSession manda o vídeo ao
        // Discord e relança — a fila/endpoint reporta `login_failed`.
        await runRecordedSession(headless, 'login no TikTok', async (context) => {
            const page = await context.newPage();

            let loggedIn = false;
            if (this.email && this.password) {
                loggedIn = await this.tryEmailLogin(page);
            } else {
                logger.warn('Sem email/senha no .env — login automático indisponível.');
            }

            if (!loggedIn) {
                // Microserviço headless: sem QR Code. Faça login localmente e
                // copie o cookie para o servidor.
                const reason = this.email
                    ? 'Login por email/senha não concluiu (credenciais/captcha) — faça login local e copie o cookie.'
                    : 'Sem TIKTOK_ACCOUNT_EMAIL/PASSWORD — gere o cookie localmente e copie para o servidor.';
                throw new LoginFailedError(accountName, reason);
            }

            const cookies = (await context.cookies()) as Cookie[];
            await saveCookies(accountName, cookies);
            logger.info(`Login concluído. Cookies salvos para '${accountName}'.`);
        });
    }

    /** Preenche email/senha e submete. Retorna true se logou dentro do tempo. */
    private async tryEmailLogin(page: Page): Promise<boolean> {
        try {
            logger.info('Login: abrindo tela de email/senha...');
            await page.goto(LOGIN_EMAIL_URL);
            await page.waitForSelector(LOGIN_EMAIL_INPUT, { timeout: 15_000 });
            logger.info('Login: preenchendo email e senha...');
            await page.fill(LOGIN_EMAIL_INPUT, this.email);
            await page.fill(LOGIN_PASSWORD_INPUT, this.password);
            await page.click(LOGIN_SUBMIT_BUTTON);
            logger.info('Login: submetido, aguardando redirect...');
            return await this.waitForLogin(page, EMAIL_LOGIN_TIMEOUT_MS);
        } catch (error) {
            logger.warn(
                `Falha no login por email/senha: ${error instanceof Error ? error.message : error}`,
            );
            return false;
        }
    }

    /** Aguarda a navegação sair das telas de login (timeout em ms). */
    private async waitForLogin(page: Page, timeoutMs: number): Promise<boolean> {
        const deadline = Date.now() + timeoutMs;
        while (Date.now() < deadline) {
            if (LOGGED_IN_URL_PATTERN.test(page.url())) {
                return true;
            }
            if (await this.hasCaptcha(page)) {
                logger.info('Login: captcha detectado. Tentando resolver...');
                await resolveCaptcha(page, this.solver);
                await sleep(1000);
                continue;
            }
            await sleep(500);
        }
        return false;
    }

    private async hasCaptcha(page: Page): Promise<boolean> {
        return (
            (await page.locator(CAPTCHA_CONTAINER).isVisible()) ||
            (await page.locator(CAPTCHA_QUESTION).isVisible())
        );
    }
}
