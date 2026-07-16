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

const EnvSchema = z.object({
    // Reencode. Recodifica em qualidade constante (CQ/CRF 18) só quando o bitrate
    // do stream de vídeo está abaixo do limiar. Sem bitrate-alvo fixo.
    // Sem S3: o Laravel envia o binário e recebe o resultado (regra da casa).
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
    reencodeEnabled: env['REENCODE_ENABLED'],
    reencodeBitrateThresholdKbps: env['REENCODE_BITRATE_THRESHOLD_KBPS'],

    discordWebhookUrl: env['DISCORD_WEBHOOK_URL'],

    apiPort: env['API_PORT'],
    apiToken: env['API_TOKEN'],
});
