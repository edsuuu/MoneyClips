import { basename, extname } from 'node:path';
import type { Locator, Page } from 'playwright';

import { settings } from '@/Config/Env';
import { logger } from '@/Config/Logger';
import * as Selectors from '@/Constants/Selectors';
import { LoginFailedError } from '@/Exceptions/LoginFailedError';
import { TikTokContentRestrictionError } from '@/Exceptions/TikTokContentRestrictionError';
import { runRecordedSession } from '@/Services/Browser';
import type { UploadResult, VideoMetadata } from '@/Types/DomainType';
import type { UploadRequest } from '@/Types/UploadType';
import { sleep } from '@/Utils/Sleep';

import { CaptchaSolver } from './Captcha/CaptchaSolver';
import { resolveCaptcha } from './Captcha/ResolveCaptcha';

const UPLOAD_UI_TIMEOUT_MS = 60_000;
const DESCRIPTION_AUTOFILL_WAIT_MS = 2500;

// A verificação de conteúdo do TikTok pode levar ~10 min (spinner "Verificação em andamento").
const POST_READY_TIMEOUT_MS = 15 * 60_000;

const POST_CONFIRM_TIMEOUT_MS = 60_000;
const POST_CONFIRM_POLL_MS = 1_000;
const POST_BUTTON_STABLE_WITHOUT_CHECK_MS = 10_000;
const CONTENT_CHECK_SCROLL_INTERVAL_MS = 5_000;
const PENDING_LOG_INTERVAL_MS = 30_000;

export class TikTokUploader {
    private readonly solver: CaptchaSolver;
    private readonly headless: boolean;
    private readonly dryRun: boolean;

    public constructor(
        solver: CaptchaSolver = new CaptchaSolver(),
        headless: boolean = !settings.showBrowser,
        dryRun: boolean = settings.dryRun,
    ) {
        this.solver = solver;
        this.headless = headless;
        this.dryRun = dryRun;
    }

    public async upload(request: UploadRequest): Promise<UploadResult> {
        const { videoPath, metadata, cookies } = request;

        logger.info(`[1/7] Abrindo navegador (headless=${this.headless})...`);
        return runRecordedSession(this.headless, 'upload no TikTok', async (context) => {
            await context.addCookies(cookies);

            const page = await context.newPage();

            logger.info('[2/7] Navegando para a página de upload...');
            await this.gotoUploadWithRetry(page);

            logger.info(`[3/7] Validando sessão. URL atual: ${page.url()}`);
            await this.assertSessionValid(page);

            logger.info('[4/7] Aguardando área de upload (ou captcha)...');
            await this.waitForUploadUiOrCaptcha(page);

            logger.info(`[5/7] Injetando vídeo: ${videoPath}`);
            await this.setVideoFile(page, videoPath);

            logger.info('[6/7] Preenchendo descrição e hashtags...');
            await this.fillDescriptionAndHashtags(page, videoPath, metadata);

            logger.info('[7/7] Aguardando processamento (botão Post habilitar)...');
            await this.waitForUploadReady(page);

            if (this.dryRun) {
                // waitForUploadReady já lançaria restrição/erro; aqui só encerra sem publicar.
                logger.warn('DRY_RUN ativo: processo concluído SEM publicar.');

                return 'dry-run';
            }

            return await this.submit(page);
        });
    }

    private async assertSessionValid(page: Page): Promise<void> {
        await sleep(1500);
        if (Selectors.LOGIN_REDIRECT_PATTERN.test(page.url())) {
            throw new LoginFailedError(
                'tiktok',
                'Sessão inválida — os cookies enviados não estão logados (redirect para login).',
            );
        }
    }

    private async gotoUploadWithRetry(page: Page): Promise<void> {
        for (let attempt = 1; attempt <= 2; attempt += 1) {
            try {
                await page.goto(Selectors.UPLOAD_URL, { timeout: 30_000 });

                return;
            } catch (error) {
                logger.warn(
                    `Tentativa ${attempt}/2 de abrir a página de upload falhou: ` +
                        `${error instanceof Error ? error.message : String(error)}`,
                );

                if (attempt === 2) {
                    throw new Error('A página do TikTok não carregou. Tente novamente.');
                }

                await sleep(5000);
            }
        }
    }

    private async waitForUploadUiOrCaptcha(page: Page): Promise<void> {
        const deadline = Date.now() + UPLOAD_UI_TIMEOUT_MS;

        while (Date.now() < deadline) {
            if (await page.locator(Selectors.UPLOAD_TEXT_CONTAINER).isVisible()) {
                return;
            }

            if (
                (await page.locator(Selectors.CAPTCHA_QUESTION).isVisible()) ||
                (await page.locator(Selectors.CAPTCHA_CONTAINER).isVisible())
            ) {
                await resolveCaptcha(page, this.solver);

                return;
            }

            await sleep(100);
        }

        throw new Error('A área de upload não apareceu a tempo (possível bloqueio do TikTok).');
    }

    private async setVideoFile(page: Page, videoPath: string): Promise<void> {
        try {
            await page.setInputFiles(Selectors.VIDEO_FILE_INPUT, videoPath);
        } catch (error) {
            logger.warn(
                `Falha ao injetar o vídeo (${videoPath}): ` +
                    `${error instanceof Error ? error.message : String(error)}`,
            );

            throw new Error(
                'Falha ao injetar o arquivo de vídeo. Verifique o caminho e a conexão.',
            );
        }
    }

    private async fillDescriptionAndHashtags(
        page: Page,
        videoPath: string,
        metadata: VideoMetadata,
    ): Promise<void> {
        await page.waitForSelector(Selectors.DESCRIPTION_EDITOR);
        await sleep(500);
        await this.dismissPopups(page);

        const editor = page.locator(Selectors.DESCRIPTION_EDITOR);
        await this.humanClick(editor);
        logger.info('Aguardando o TikTok carregar o vídeo nos servidores...');

        await sleep(DESCRIPTION_AUTOFILL_WAIT_MS);
        await this.clearDescriptionEditor(page);
        await this.typeDescription(page, metadata);

        const fileStem = this.fileStem(videoPath);
        const currentText = (await editor.textContent()) ?? '';
        if (currentText.includes(fileStem) && !metadata.title.includes(fileStem)) {
            logger.warn(`Descrição continha o nome do arquivo (${fileStem}); reescrevendo.`);
            await this.clearDescriptionEditor(page);
            await this.typeDescription(page, metadata);
        }

        for (const hashtag of metadata.hashtags) {
            await this.typeHashtag(page, hashtag);
        }
        logger.info('Descrição e hashtags adicionadas.');
    }

    private async clearDescriptionEditor(page: Page): Promise<void> {
        const editor = page.locator(Selectors.DESCRIPTION_EDITOR);

        for (const shortcut of ['ControlOrMeta+A', 'Control+A', 'Meta+A']) {
            await this.humanClick(editor);
            await page.keyboard.press(shortcut);
            await page.keyboard.press('Backspace');
            await sleep(250);

            const text = ((await editor.textContent()) ?? '').trim();
            if (text === '') {
                return;
            }
        }

        logger.warn('Não foi possível confirmar que o editor foi limpo antes de escrever.');
    }

    private async typeDescription(page: Page, metadata: VideoMetadata): Promise<void> {
        await sleep(500);
        await this.humanType(page, metadata.title);
    }

    private fileStem(videoPath: string): string {
        const filename = basename(videoPath);
        return filename.slice(0, filename.length - extname(filename).length);
    }

    private async typeHashtag(page: Page, hashtag: string): Promise<void> {
        await page.keyboard.type(hashtag);
        await sleep(500);

        try {
            await page.click(`${Selectors.HASHTAG_SUGGESTION}:has-text("${hashtag}")`, {
                timeout: 1000,
            });
        } catch (exactError) {
            logger.warn(
                `Sugestão exata de ${hashtag} não apareceu: ` +
                    `${exactError instanceof Error ? exactError.message : String(exactError)}`,
            );

            try {
                await page.click(Selectors.HASHTAG_SUGGESTION, { timeout: 1000 });
            } catch (anyError) {
                logger.warn(
                    `Sem sugestão para ${hashtag} ` +
                        `(${anyError instanceof Error ? anyError.message : String(anyError)}); ` +
                        'inserida como texto.',
                );

                await page.keyboard.type(' ');
            }
        }
    }

    private async dismissPopups(page: Page): Promise<void> {
        // Tour de onboarding (react-joyride) fica por cima e intercepta os cliques.
        try {
            await page.evaluate(() => {
                document.getElementById('react-joyride-portal')?.remove();
                document.querySelector('.react-joyride__overlay')?.remove();
            });
        } catch (error) {
            logger.warn(
                `Não foi possível remover o overlay do tour: ` +
                    `${error instanceof Error ? error.message : String(error)}`,
            );
        }

        for (const label of ['Cancel', 'Got it', 'Cancelar', 'Entendi']) {
            const button = page.locator(`button:has-text('${label}')`).first();

            try {
                if (await button.isVisible()) {
                    await button.click();
                }
            } catch (error) {
                logger.warn(
                    `Popup '${label}' não pôde ser fechado: ` +
                        `${error instanceof Error ? error.message : String(error)}`,
                );
            }
        }
    }

    private async waitForUploadReady(page: Page): Promise<void> {
        const deadline = Date.now() + POST_READY_TIMEOUT_MS;
        let postButtonEnabledAt: number | null = null;
        let contentCheckSeen = false;
        let contentCheckPassedSeen = false;
        let lastBottomScrollAt = 0;
        let lastPendingLogAt = 0;

        // Descrição/hashtags já preenchidas: rola até o fim pra revelar a verificação antes de capturar erros.
        await this.scrollToUploadBottom(page);

        while (Date.now() < deadline) {
            const now = Date.now();
            if (now - lastBottomScrollAt >= CONTENT_CHECK_SCROLL_INTERVAL_MS) {
                await this.scrollToUploadBottom(page);
                lastBottomScrollAt = now;
            }

            const restriction = await this.detectContentRestriction(page);
            if (restriction !== null) {
                throw new TikTokContentRestrictionError(restriction);
            }

            const contentCheckPassed = await this.hasVisibleText(
                page,
                Selectors.CONTENT_CHECK_PASSED_SNIPPETS,
            );
            const contentCheckPending = await this.hasVisibleText(
                page,
                Selectors.CONTENT_CHECK_PENDING_SNIPPETS,
            );
            contentCheckPassedSeen = contentCheckPassedSeen || contentCheckPassed;
            contentCheckSeen = contentCheckSeen || contentCheckPassedSeen || contentCheckPending;

            if (contentCheckPending && !contentCheckPassedSeen) {
                if (now - lastPendingLogAt >= PENDING_LOG_INTERVAL_MS) {
                    logger.info(
                        'Verificação de conteúdo em andamento (pode levar ~10 min); aguardando...',
                    );
                    lastPendingLogAt = now;
                }

                await sleep(POST_CONFIRM_POLL_MS);
                continue;
            }

            const postButtonEnabled = await this.isVisible(page, Selectors.POST_BUTTON_ENABLED);
            if (postButtonEnabled && contentCheckPassedSeen) {
                logger.info('Verificação de conteúdo concluída sem restrições.');
                return;
            }

            if (postButtonEnabled) {
                postButtonEnabledAt ??= now;
                if (
                    !contentCheckSeen &&
                    now - postButtonEnabledAt >= POST_BUTTON_STABLE_WITHOUT_CHECK_MS
                ) {
                    logger.warn(
                        'Botão Post habilitado, mas a UI de verificação de conteúdo não foi localizada; seguindo após estabilidade.',
                    );
                    return;
                }
            } else {
                postButtonEnabledAt = null;
            }

            const uiError = await this.detectTikTokError(page);
            if (uiError !== null) {
                throw new Error(`TikTok recusou o vídeo no processamento: "${uiError}"`);
            }
            await sleep(POST_CONFIRM_POLL_MS);
        }

        const reason = contentCheckSeen
            ? 'TikTok não concluiu a verificação de conteúdo a tempo; sessão encerrada.'
            : 'TikTok não liberou o post a tempo (vídeo não processou); sessão encerrada.';
        throw new Error(reason);
    }

    private async submit(page: Page): Promise<UploadResult> {
        const restriction = await this.detectContentRestriction(page);
        if (restriction !== null) {
            throw new TikTokContentRestrictionError(restriction);
        }

        await page
            .locator(Selectors.POST_BUTTON_ENABLED)
            .first()
            .scrollIntoViewIfNeeded()
            .catch((error: unknown) =>
                logger.warn(`scrollIntoView do botão Post falhou: ${String(error)}`),
            );

        try {
            await page.click(Selectors.POST_BUTTON, { timeout: 2000 });
        } catch (error) {
            logger.warn(`Clique no Post falhou (${String(error)}); tentando o botão habilitado.`);

            await page
                .click(Selectors.POST_BUTTON_ENABLED, { timeout: 2000 })
                .catch((fallbackError: unknown) =>
                    logger.warn(`Fallback do clique no Post falhou: ${String(fallbackError)}`),
                );
        }

        await page
            .locator(Selectors.POST_NOW_BUTTON)
            .click({ timeout: 3000 })
            .catch((error: unknown) =>
                logger.warn(`Botão 'Post now' ausente/ignorado: ${String(error)}`),
            );

        const deadline = Date.now() + POST_CONFIRM_TIMEOUT_MS;

        while (Date.now() < deadline) {
            if (page.url().startsWith(Selectors.CONTENT_URL)) {
                logger.info('Upload concluído (redirect para /content).');

                return 'completed';
            }

            if (await this.isVisible(page, Selectors.UPLOAD_SUCCESS_TOAST)) {
                logger.info('Upload concluído (confirmado pelo aviso de sucesso).');

                return 'completed';
            }

            if (await this.isVisible(page, Selectors.UPLOAD_SUCCESS_MODAL)) {
                logger.info('Upload concluído (confirmado pelo modal de sucesso).');

                return 'completed';
            }

            const restrictionAfterClick = await this.detectContentRestriction(page);

            if (restrictionAfterClick !== null) {
                throw new TikTokContentRestrictionError(restrictionAfterClick);
            }

            const uiError = await this.detectTikTokError(page);

            if (uiError !== null) {
                throw new Error(`TikTok recusou a publicação após o clique em Post: "${uiError}"`);
            }

            await sleep(POST_CONFIRM_POLL_MS);
        }

        throw new Error(
            `Upload não confirmado pelo TikTok após ${POST_CONFIRM_TIMEOUT_MS / 1000}s ` +
                `(url final: ${page.url()}).`,
        );
    }

    private async detectContentRestriction(page: Page): Promise<string | null> {
        return await this.visibleTextForSnippets(page, Selectors.CONTENT_RESTRICTION_SNIPPETS);
    }

    private async detectTikTokError(page: Page): Promise<string | null> {
        return await this.visibleTextForSnippets(page, Selectors.UPLOAD_ERROR_SNIPPETS);
    }

    private async hasVisibleText(page: Page, snippets: readonly string[]): Promise<boolean> {
        return (await this.visibleTextForSnippets(page, snippets)) !== null;
    }

    private async visibleTextForSnippets(
        page: Page,
        snippets: readonly string[],
    ): Promise<string | null> {
        for (const snippet of snippets) {
            try {
                const el = page.locator(`:has-text("${snippet}")`).last();

                if (await el.isVisible()) {
                    const text = ((await el.textContent()) ?? snippet).trim();

                    return text.length > 200 ? `${text.slice(0, 200)}…` : text;
                }
            } catch (error) {
                logger.warn(
                    `Checagem do trecho "${snippet}" falhou (página navegou?): ` +
                        `${error instanceof Error ? error.message : String(error)}`,
                );
            }
        }

        return null;
    }

    private async scrollToUploadBottom(page: Page): Promise<void> {
        // A página de upload rola num container interno (não no window), então window.scrollTo
        // não faz nada. scrollIntoViewIfNeeded rola o scroller correto e aparece no vídeo.
        for (const selector of [Selectors.CONTENT_CHECK_CARD, Selectors.POST_BUTTON]) {
            try {
                const element = page.locator(selector).first();
                if ((await element.count()) === 0) {
                    continue;
                }

                await element.scrollIntoViewIfNeeded();

                return;
            } catch (error) {
                logger.warn(
                    `Scroll até "${selector}" falhou: ` +
                        `${error instanceof Error ? error.message : String(error)}`,
                );
            }
        }
    }

    private async isVisible(page: Page, selector: string): Promise<boolean> {
        try {
            return await page.locator(selector).first().isVisible();
        } catch (error) {
            logger.warn(
                `Checagem de visibilidade de "${selector}" falhou: ` +
                    `${error instanceof Error ? error.message : String(error)}`,
            );

            return false;
        }
    }

    private async humanClick(locator: Locator): Promise<void> {
        await locator.hover();
        await sleep(this.randomDelay(120, 320));
        await locator.click();
    }

    private async humanType(page: Page, text: string): Promise<void> {
        for (const char of text) {
            await page.keyboard.type(char);
            await sleep(this.randomDelay(25, 90));
        }
    }

    private randomDelay(min: number, max: number): number {
        return Math.floor(Math.random() * (max - min + 1)) + min;
    }
}
