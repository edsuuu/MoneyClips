/** Configuração: lê o ambiente (SCREAMING_SNAKE) com defaults e valida via zod. */

import 'dotenv/config';
import { z } from 'zod';

const TRUTHY = new Set(['1', 'true', 'yes', 'on', 'sim']);

function asBool(value: unknown, fallback: boolean): boolean {
    if (typeof value === 'boolean') {
        return value;
    }
    if (typeof value !== 'string' || value.trim() === '') {
        return fallback;
    }
    return TRUTHY.has(value.trim().toLowerCase());
}

const boolField = (def: boolean) => z.preprocess((v) => asBool(v, def), z.boolean());

const strField = (def: string) =>
    z.preprocess((v) => (v === undefined ? def : String(v)), z.string());

const intField = (def: number) =>
    z.preprocess((v) => {
        const parsed = typeof v === 'string' && v.trim() !== '' ? Number(v) : Number.NaN;
        return Number.isInteger(parsed) ? parsed : def;
    }, z.number().int());

const reqStrField = z.preprocess((v) => (v === undefined ? '' : String(v)), z.string().min(1));

const EnvSchema = z.object({
    // Contabo Object Storage (S3-compatível).
    awsAccessKeyId: reqStrField,
    awsSecretAccessKey: reqStrField,
    awsEndpoint: reqStrField,
    awsRegion: strField('us-east-1'),
    awsBucket: reqStrField,
    s3Prefix: strField('shorts/'),
    // Retry do download do S3 (erros transitórios: ECONNREFUSED, timeout, 5xx).
    // Tentativas totais e atraso base (backoff exponencial: base, base*2, base*4...).
    s3DownloadRetries: intField(3),
    s3DownloadRetryDelayMs: intField(2000),

    // TikTok. Email/senha vazios = login automático indisponível (use cookie manual).
    tiktokAccountName: reqStrField,
    tiktokEmail: strField(''),
    tiktokPassword: strField(''),

    // Roboflow (captcha). Default = chave pública da lib original.
    roboflowApiKey: strField('kyHFbAWkOWfGz8fSEw8O'),

    // Automação. dryRun=true faz tudo menos publicar.
    headless: boolField(false),
    stealth: boolField(false),
    dryRun: boolField(true),

    // Proxy do navegador (opcional). SOCKS5 no Chromium não suporta auth (user/senha só em HTTP).
    proxyServer: strField(''),
    proxyUsername: strField(''),
    proxyPassword: strField(''),
    // Vazio = automático conforme o proxy (BR com proxy, senão US).
    browserLocale: strField(''),
    browserTimezone: strField(''),

    // Discord (avisa qualquer erro) e porta da API (própria, fora da do Laravel).
    discordWebhookUrl: strField(''),
    apiPort: intField(8090),
    // Token opcional. Se preenchido, POST /posts e GET /session exigem
    // Authorization: Bearer <token> (ou header X-Api-Token). Vazio = aberto (dev).
    apiToken: strField(''),
});

export type Settings = z.infer<typeof EnvSchema>;

const env = process.env;

export const settings: Settings = EnvSchema.parse({
    awsAccessKeyId: env['AWS_ACCESS_KEY_ID'],
    awsSecretAccessKey: env['AWS_SECRET_ACCESS_KEY'],
    awsEndpoint: env['AWS_ENDPOINT'],
    awsRegion: env['AWS_REGION'],
    awsBucket: env['AWS_BUCKET'],
    s3Prefix: env['S3_PREFIX'],
    s3DownloadRetries: env['S3_DOWNLOAD_RETRIES'],
    s3DownloadRetryDelayMs: env['S3_DOWNLOAD_RETRY_DELAY_MS'],

    tiktokAccountName: env['TIKTOK_ACCOUNT_NAME'],
    tiktokEmail: env['TIKTOK_ACCOUNT_EMAIL'],
    tiktokPassword: env['TIKTOK_ACCOUNT_PASSWORD'],

    roboflowApiKey: env['ROBOFLOW_API_KEY'],

    headless: env['HEADLESS'],
    stealth: env['STEALTH'],
    dryRun: env['DRY_RUN'],

    proxyServer: env['PROXY_SERVER'],
    proxyUsername: env['PROXY_USERNAME'],
    proxyPassword: env['PROXY_PASSWORD'],
    browserLocale: env['BROWSER_LOCALE'],
    browserTimezone: env['BROWSER_TIMEZONE'],

    discordWebhookUrl: env['DISCORD_WEBHOOK_URL'],

    apiPort: env['API_PORT'],
    apiToken: env['API_TOKEN'],
});
