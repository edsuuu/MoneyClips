export const UPLOAD_URL = 'https://www.tiktok.com/tiktokstudio/upload?from=upload&lang=en';
export const CONTENT_URL = 'https://www.tiktok.com/tiktokstudio/content';

export const LOGIN_EMAIL_URL = 'https://www.tiktok.com/login/phone-or-email/email';

export const QR_LOGIN_URL = 'https://www.tiktok.com/login/qrcode';

export const LOGGED_IN_URL_PATTERN = /^https:\/\/www\.tiktok\.com\/(foryou|tiktokstudio|@)/;

export const NAV_PROFILE_LINK = 'a[data-e2e="nav-profile"]';

export const SESSION_CHECK_URL = 'https://www.tiktok.com/foryou';

export const LOGIN_REDIRECT_PATTERN = /\/login/;

export const LOGIN_EMAIL_INPUT = 'input[name="username"]';
export const LOGIN_PASSWORD_INPUT = 'input[type="password"]';
export const LOGIN_SUBMIT_BUTTON = 'button[data-e2e="login-button"]';

export const LOGIN_ERROR_SNIPPETS = [
    'Ocorreu um erro',
    'tente novamente mais tarde',
    'Something went wrong',
    'try again later',
    'Maximum number of attempts',
    'account or password',
    'is incorrect',
    'senha',
] as const;

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
// Card de verificação: só renderiza após o vídeo subir pros servidores (upload confirmado).
export const CONTENT_CHECK_CARD = '[data-e2e="copyright_container"]';
export const POST_BUTTON = 'button:has-text("Post")[data-e2e="post_video_button"]';
export const POST_BUTTON_ENABLED = 'button:has-text("Post")[aria-disabled="false"]';
export const POST_NOW_BUTTON = 'button:has-text("Post now")';
export const UPLOAD_SUCCESS_TOAST = ':has-text("Leaving the page does not interrupt")';
export const UPLOAD_SUCCESS_MODAL = ':has-text("Your video has been uploaded")';

export const CONTENT_RESTRICTION_SNIPPETS = [
    'Unoriginal, low-quality, and QR code content',
    'Content may be restricted',
    'Violation reason',
    'Violation details',
    'not eligible for recommendation',
    'O conteúdo pode estar restrito',
] as const;

export const CONTENT_CHECK_PASSED_SNIPPETS = [
    'No issues found',
    'No issues detected',
    'Checks complete',
    'Content check complete',
    'Content check passed',
    'Your video is ready to post',
    'Video is ready to post',
    'Nenhum problema encontrado',
] as const;

export const CONTENT_CHECK_PENDING_SNIPPETS = [
    'Checking your video',
    'Checking video',
    'Checking content',
    'Checking for issues',
    'Content check in progress',
    'This may take a few minutes',
    'Verificação em andamento',
    'Verificaremos se o seu conteúdo',
] as const;

export const UPLOAD_ERROR_SNIPPETS = [
    'Something went wrong',
    'violates our Community Guidelines',
    "couldn't be uploaded",
    'Upload failed',
    'Post failed',
    'try again or replace it',
    'Algo deu errado',
] as const;
