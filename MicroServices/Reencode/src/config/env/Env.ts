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
    // Storage S3-compatível (MinIO em dev, Contabo em prod).
    awsAccessKeyId: reqStrField,
    awsSecretAccessKey: reqStrField,
    awsEndpoint: reqStrField,
    awsRegion: strField('us-east-1'),
    awsBucket: reqStrField,
    s3Prefix: strField('shorts/'),
    // Retry do download do S3 (erros transitórios: ECONNREFUSED, timeout, 5xx).
    s3DownloadRetries: intField(3),
    s3DownloadRetryDelayMs: intField(2000),

    // Reencode. Recodifica em qualidade constante (CQ/CRF 18) só quando o bitrate
    // do stream de vídeo está abaixo do limiar. Sem bitrate-alvo fixo.
    reencodeEnabled: boolField(true),
    reencodeBitrateThresholdKbps: intField(4000),

    // Discord (avisa qualquer erro) e porta da API.
    discordWebhookUrl: strField(''),
    apiPort: intField(8790),
    // Token opcional. Se preenchido, POST /reencode exige
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

    reencodeEnabled: env['REENCODE_ENABLED'],
    reencodeBitrateThresholdKbps: env['REENCODE_BITRATE_THRESHOLD_KBPS'],

    discordWebhookUrl: env['DISCORD_WEBHOOK_URL'],

    apiPort: env['API_PORT'],
    apiToken: env['API_TOKEN'],
});
