/**
 * Automação de upload no TikTok Studio via Playwright — porta enxuta do
 * upload_tiktok do function.py, focada em postagem imediata (sem agendamento).
 *
 * Fluxo: garante sessão válida (TikTokAuth) -> abre a página de upload ->
 * valida que está logado -> resolve captcha se houver -> injeta o vídeo ->
 * preenche descrição/hashtags -> publica (a menos que DRY_RUN esteja ativo).
 */

import { basename, extname } from 'node:path';
import type { Page } from 'playwright';

import { TikTokAuth } from '@/auth/TikTokAuth';
import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';
import type { UploadResult, VideoMetadata } from '@/types/DomainType';
import type { UploadRequest } from '@/types/UploadType';
import { sleep } from '@/utils/Sleep';

import { logExitIp, runRecordedSession } from './Browser';
import { CaptchaSolver } from './captcha/CaptchaSolver';
import { resolveCaptcha } from './captcha/ResolveCaptcha';
import { humanClick, humanType } from './Humanize';
import {
    CAPTCHA_CONTAINER,
    CAPTCHA_QUESTION,
    CONTENT_CHECK_PASSED_SNIPPETS,
    CONTENT_CHECK_PENDING_SNIPPETS,
    CONTENT_RESTRICTION_SNIPPETS,
    CONTENT_URL,
    DESCRIPTION_EDITOR,
    HASHTAG_SUGGESTION,
    LOGIN_REDIRECT_PATTERN,
    POST_BUTTON,
    POST_BUTTON_ENABLED,
    POST_NOW_BUTTON,
    UPLOAD_ERROR_SNIPPETS,
    UPLOAD_SUCCESS_MODAL,
    UPLOAD_SUCCESS_TOAST,
    UPLOAD_TEXT_CONTAINER,
    UPLOAD_URL,
    VIDEO_FILE_INPUT,
} from './Selectors';
import { TikTokContentRestrictionError } from './TikTokContentRestrictionError';

/** Tempo máximo esperando a UI de upload (ou captcha) aparecer. */
const UPLOAD_UI_TIMEOUT_MS = 60_000;
const DESCRIPTION_AUTOFILL_WAIT_MS = 2500;
// Tempo máximo esperando o TikTok processar o vídeo e liberar o post. Curto de
// propósito: a fila é serial (concorrência 1), então não dá para segurar tudo.
// Se estourar, encerra a sessão e reporta erro no webhook.
const POST_READY_TIMEOUT_MS = 180_000;
// Janela de confirmação pós-clique em "Post". Os 5s antigos geravam falso
// negativo quando o redirect/toast demorava (ou a URL vinha com query string).
const POST_CONFIRM_TIMEOUT_MS = 60_000;
const POST_CONFIRM_POLL_MS = 1_000;
const POST_BUTTON_STABLE_WITHOUT_CHECK_MS = 10_000;
const CONTENT_CHECK_SCROLL_INTERVAL_MS = 5_000;

export class TikTokUploader {
    private readonly auth: TikTokAuth;
    private readonly solver: CaptchaSolver;
    private readonly headless: boolean;
    private readonly dryRun: boolean;

    public constructor(
        auth: TikTokAuth = new TikTokAuth(),
        solver: CaptchaSolver = new CaptchaSolver(),
        headless: boolean = settings.headless,
        dryRun: boolean = settings.dryRun,
    ) {
        this.auth = auth;
        this.solver = solver;
        this.headless = headless;
        this.dryRun = dryRun;
    }

    public async upload(request: UploadRequest): Promise<UploadResult> {
        const { videoPath, metadata, accountName } = request;

        // Garante sessão válida (reutiliza cookies, ou faz login se preciso).
        logger.info('[1/8] Garantindo sessão (cookies/login)...');
        const cookies = await this.auth.ensureSession(accountName);

        logger.info(`[2/8] Abrindo navegador (headless=${this.headless})...`);
        return runRecordedSession(this.headless, 'upload no TikTok', async (context) => {
            // Loga o IP público de saída (passa pelo proxy/VPN, se houver) para
            // confirmar de qual IP o post está realmente saindo.
            await logExitIp(context);
            await context.addCookies(cookies);
            const page = await context.newPage();

            logger.info(`[3/8] Navegando para a página de upload (conta '${accountName}')...`);
            await this.gotoUploadWithRetry(page);

            logger.info(`[4/8] Validando sessão. URL atual: ${page.url()}`);
            await this.assertSessionValid(page, accountName);

            logger.info('[5/8] Aguardando área de upload (ou captcha)...');
            await this.waitForUploadUiOrCaptcha(page);

            logger.info(`[6/8] Injetando vídeo: ${videoPath}`);
            await this.setVideoFile(page, videoPath);

            logger.info('[7/8] Preenchendo descrição e hashtags...');
            await this.fillDescriptionAndHashtags(page, videoPath, metadata);

            logger.info('[8/8] Aguardando processamento (botão Post habilitar)...');
            await this.waitForUploadReady(page);

            if (this.dryRun) {
                // Microserviço: NÃO segura o navegador aberto — isso travaria a
                // fila (concorrência 1). Para depurar visualmente, use
                // HEADLESS=false e acompanhe ao vivo (ou o vídeo gravado).
                logger.warn('DRY_RUN ativo: processo concluído SEM publicar.');
                return 'dry-run';
            }
            return await this.submit(page);
        });
    }

    /** Após carregar a página, confirma que não houve redirect para o login. */
    private async assertSessionValid(page: Page, accountName: string): Promise<void> {
        await sleep(1500);
        if (LOGIN_REDIRECT_PATTERN.test(page.url())) {
            throw new Error(
                `Sessão inválida para '${accountName}'. ` +
                    `Apague cookies/${accountName}.json e rode de novo para refazer o login.`,
            );
        }
    }

    private async gotoUploadWithRetry(page: Page): Promise<void> {
        for (let attempt = 1; attempt <= 2; attempt += 1) {
            try {
                await page.goto(UPLOAD_URL, { timeout: 30_000 });
                return;
            } catch {
                if (attempt === 2) {
                    throw new Error('A página do TikTok não carregou. Tente novamente.');
                }
                await sleep(5000);
            }
        }
    }

    /** Espera a UI de upload OU o captcha; resolve o captcha quando aparece. */
    private async waitForUploadUiOrCaptcha(page: Page): Promise<void> {
        const deadline = Date.now() + UPLOAD_UI_TIMEOUT_MS;
        while (Date.now() < deadline) {
            if (await page.locator(UPLOAD_TEXT_CONTAINER).isVisible()) {
                return;
            }
            if (
                (await page.locator(CAPTCHA_QUESTION).isVisible()) ||
                (await page.locator(CAPTCHA_CONTAINER).isVisible())
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
            await page.setInputFiles(VIDEO_FILE_INPUT, videoPath);
        } catch {
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
        await page.waitForSelector(DESCRIPTION_EDITOR);
        await sleep(500);
        await this.dismissPopups(page);

        const editor = page.locator(DESCRIPTION_EDITOR);
        await humanClick(editor);
        logger.info('Aguardando o TikTok carregar o vídeo nos servidores...');

        // O TikTok costuma preencher o editor com o nome do arquivo alguns
        // instantes depois do upload começar; espere antes de limpar.
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
        const editor = page.locator(DESCRIPTION_EDITOR);

        for (const shortcut of ['ControlOrMeta+A', 'Control+A', 'Meta+A']) {
            await humanClick(editor);
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
        await humanType(page, metadata.title);
    }

    private fileStem(videoPath: string): string {
        const filename = basename(videoPath);
        return filename.slice(0, filename.length - extname(filename).length);
    }

    private async typeHashtag(page: Page, hashtag: string): Promise<void> {
        await page.keyboard.type(hashtag);
        await sleep(500);
        try {
            await page.click(`${HASHTAG_SUGGESTION}:has-text("${hashtag}")`, { timeout: 1000 });
        } catch {
            try {
                await page.click(HASHTAG_SUGGESTION, { timeout: 1000 });
            } catch {
                // Sem sugestão: separa a hashtag com um espaço e segue.
                await page.keyboard.type(' ');
                logger.warn(`Sem sugestão para ${hashtag}; inserida como texto.`);
            }
        }
    }

    private async dismissPopups(page: Page): Promise<void> {
        // Best-effort: fechar popups nunca deve derrubar o upload. O seletor pode
        // casar com mais de um botão (strict mode do Playwright) — usa .first() e
        // engole qualquer erro.
        for (const label of ['Cancel', 'Got it']) {
            const button = page.locator(`button:has-text('${label}')`).first();
            try {
                if (await button.isVisible()) {
                    await button.click();
                }
            } catch {
                // Popup ausente ou seletor ambíguo: ignora e segue.
            }
        }
    }

    private async waitForUploadReady(page: Page): Promise<void> {
        // Sondagem em vez de waitForSelector: enquanto espera o botão Post
        // habilitar, também vigia a UI de erro do TikTok — "Something went
        // wrong" no card do vídeo, por exemplo, nunca habilita o botão e só
        // estouraria o timeout genérico.
        const deadline = Date.now() + POST_READY_TIMEOUT_MS;
        let postButtonEnabledAt: number | null = null;
        let contentCheckSeen = false;
        let contentCheckPassedSeen = false;
        let lastBottomScrollAt = 0;

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
                CONTENT_CHECK_PASSED_SNIPPETS,
            );
            const contentCheckPending = await this.hasVisibleText(
                page,
                CONTENT_CHECK_PENDING_SNIPPETS,
            );
            contentCheckPassedSeen = contentCheckPassedSeen || contentCheckPassed;
            contentCheckSeen = contentCheckSeen || contentCheckPassedSeen || contentCheckPending;

            const postButtonEnabled = await this.isVisible(page, POST_BUTTON_ENABLED);
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
        // Não segura a fila: encerra esta sessão (o finally fecha o navegador)
        // e deixa o erro subir — a fila reporta `failed`/`restricted` no webhook + Discord.
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
            .locator(POST_BUTTON_ENABLED)
            .first()
            .scrollIntoViewIfNeeded()
            .catch(() => undefined);

        try {
            await page.click(POST_BUTTON, { timeout: 2000 });
        } catch {
            await page.click(POST_BUTTON_ENABLED, { timeout: 2000 }).catch(() => undefined);
        }

        // Pop-up "Continue to post? -> Post now", quando aparece.
        await page
            .locator(POST_NOW_BUTTON)
            .click({ timeout: 3000 })
            .catch(() => undefined);

        // Confirma sucesso sondando vários sinais: redirect pra /content (por
        // PREFIXO — o TikTok anexa query string, e o waitForURL antigo exigia
        // match exato da URL inteira), toast ou modal de sucesso.
        const deadline = Date.now() + POST_CONFIRM_TIMEOUT_MS;
        while (Date.now() < deadline) {
            if (page.url().startsWith(CONTENT_URL)) {
                logger.info('Upload concluído (redirect para /content).');
                return 'completed';
            }
            if (await this.isVisible(page, UPLOAD_SUCCESS_TOAST)) {
                logger.info('Upload concluído (confirmado pelo aviso de sucesso).');
                return 'completed';
            }
            if (await this.isVisible(page, UPLOAD_SUCCESS_MODAL)) {
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

        // Lança (em vez de retornar status) pra sessão gravada anexar o vídeo
        // no Discord — sem ele não dá pra saber o que o TikTok mostrou na tela.
        throw new Error(
            `Upload não confirmado pelo TikTok após ${POST_CONFIRM_TIMEOUT_MS / 1000}s ` +
                `(url final: ${page.url()}).`,
        );
    }

    private async detectContentRestriction(page: Page): Promise<string | null> {
        return await this.visibleTextForSnippets(page, CONTENT_RESTRICTION_SNIPPETS);
    }

    /**
     * Texto do erro que a UI do TikTok estiver exibindo, ou null. O `.last()`
     * pega o elemento mais interno que contém o trecho (o :has-text casa
     * também com todos os ancestrais), limitado pra não poluir o Discord.
     */
    private async detectTikTokError(page: Page): Promise<string | null> {
        return await this.visibleTextForSnippets(page, UPLOAD_ERROR_SNIPPETS);
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
            } catch {
                // Página navegou no meio da checagem — tenta o próximo trecho.
            }
        }
        return null;
    }

    private async scrollToUploadBottom(page: Page): Promise<void> {
        try {
            await page.evaluate(() => {
                const browserGlobal = globalThis as unknown as {
                    document: { body: { scrollHeight: number } };
                    scrollTo: (x: number, y: number) => void;
                };
                browserGlobal.scrollTo(0, browserGlobal.document.body.scrollHeight);
            });
        } catch {
            // Página navegou ou o frame foi recriado — a próxima sondagem tenta de novo.
        }
    }

    /** Visibilidade sem lançar — página pode navegar no meio da sondagem. */
    private async isVisible(page: Page, selector: string): Promise<boolean> {
        try {
            return await page.locator(selector).first().isVisible();
        } catch {
            return false;
        }
    }
}
