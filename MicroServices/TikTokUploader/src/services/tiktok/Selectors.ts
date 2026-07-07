/**
 * Seletores e URLs da UI do TikTok Studio. Centralizados porque a UI muda com
 * frequência — quando algo quebrar, ajuste aqui primeiro (rodando com
 * HEADLESS=false para inspecionar o DOM ao vivo).
 */

export const UPLOAD_URL = 'https://www.tiktok.com/tiktokstudio/upload?from=upload&lang=en';
export const CONTENT_URL = 'https://www.tiktok.com/tiktokstudio/content';

// Login por email/senha (sem QR Code).
export const LOGIN_EMAIL_URL = 'https://www.tiktok.com/login/phone-or-email/email';

/** Regex que indica login concluído (saiu das telas de /login). */
export const LOGGED_IN_URL_PATTERN = /^https:\/\/www\.tiktok\.com\/(foryou|tiktokstudio|@)/;

/** Se a URL bater nisto após carregar a página de upload, a sessão é inválida. */
export const LOGIN_REDIRECT_PATTERN = /\/login/;

export const LOGIN_EMAIL_INPUT = 'input[name="username"]';
export const LOGIN_PASSWORD_INPUT = 'input[type="password"]';
export const LOGIN_SUBMIT_BUTTON = 'button[data-e2e="login-button"]';

export const CAPTCHA_QUESTION = 'div.VerifyBar___StyledDiv-sc-12zaxoy-0.hRJhHT';
export const CAPTCHA_CONTAINER = '#captcha-verify-container-main-page';
export const CAPTCHA_IMAGE = 'img#captcha-verify-image';
export const CAPTCHA_REFRESH = 'span.secsdk_captcha_refresh--text';
export const CAPTCHA_SUCCESS = 'div.captcha_verify_message.captcha_verify_message-pass';
export const CAPTCHA_FAIL = 'div.captcha_verify_message.captcha_verify_message-fail';
export const CAPTCHA_SUBMIT = 'div.verify-captcha-submit-button';
export const ROTATION_CAPTCHA_IMAGES = `${CAPTCHA_CONTAINER} img[alt="Captcha"]`;
export const ROTATION_SLIDE_BUTTON = '#captcha_slide_button';
export const ROTATION_REFRESH_BUTTON = '#captcha_refresh_button';

export const UPLOAD_TEXT_CONTAINER = '.upload-text-container';
export const VIDEO_FILE_INPUT = 'input[type="file"][accept="video/*"]';
export const DESCRIPTION_EDITOR = 'div[data-contents="true"]';
export const HASHTAG_SUGGESTION = 'span.hash-tag-topic';
export const POST_BUTTON = 'button:has-text("Post")[data-e2e="post_video_button"]';
export const POST_BUTTON_ENABLED = 'button:has-text("Post")[aria-disabled="false"]';
export const POST_NOW_BUTTON = 'button:has-text("Post now")';
export const UPLOAD_SUCCESS_TOAST = ':has-text("Leaving the page does not interrupt")';
export const UPLOAD_SUCCESS_MODAL = ':has-text("Your video has been uploaded")';

/**
 * Trechos de texto que o TikTok exibe quando recusa o vídeo (no card de
 * processamento ou após o clique em Post). Detectados um a um pra mensagem
 * de erro do Discord dizer exatamente o que a UI mostrou — "Something went
 * wrong" foi o caso real de 03/07/2026 que o genérico "Upload não confirmado"
 * escondia. "Content may be restricted" (modal de moderação: unoriginal /
 * low-quality / QR code) foi o caso de 07/07/2026 — o modal trava o fluxo e
 * caía no mesmo timeout genérico. Ordem importa: do mais específico pro mais
 * genérico.
 */
export const UPLOAD_ERROR_SNIPPETS = [
    'Unoriginal, low-quality, and QR code content',
    'Content may be restricted',
    'Violation reason',
    'Something went wrong',
    'violates our Community Guidelines',
    "couldn't be uploaded",
    'Upload failed',
    'Post failed',
    'try again or replace it',
] as const;
