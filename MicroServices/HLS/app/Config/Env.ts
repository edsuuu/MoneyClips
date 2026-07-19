import 'dotenv/config';

const TRUTHY = new Set(['1', 'true', 'yes', 'on', 'sim']);

function asBool(value: string | undefined, fallback: boolean): boolean {
    if (value === undefined || value.trim() === '') {
        return fallback;
    }
    return TRUTHY.has(value.trim().toLowerCase());
}

function asStr(value: string | undefined, fallback = ''): string {
    return value === undefined || value === '' ? fallback : value;
}

function asInt(value: string | undefined, fallback: number): number {
    const parsed = value !== undefined && value.trim() !== '' ? Number(value) : Number.NaN;
    return Number.isInteger(parsed) ? parsed : fallback;
}

function asEncoder(value: string | undefined): 'gpu' | 'cpu' {
    return value === 'cpu' ? 'cpu' : 'gpu';
}

export interface Settings {
    encoder: 'gpu' | 'cpu';
    segmentSeconds: number;
    workDir: string;
    storageEndpoint: string;
    storageRegion: string;
    storageBucket: string;
    storageAccessKey: string;
    storageSecretKey: string;
    storageForcePathStyle: boolean;
    apiPort: number;
    /** Vazio = endpoints abertos (dev); setado = exige Bearer token. */
    apiToken: string;
    /** Compartilhado com o Laravel — autentica o webhook do desfecho (fail-closed do lado de lá). */
    observabilityToken: string;
}

const env = process.env;

export const settings: Settings = {
    encoder: asEncoder(env['HLS_ENCODER']),
    segmentSeconds: asInt(env['HLS_SEGMENT_SECONDS'], 6),
    workDir: asStr(env['HLS_WORK_DIR']),
    storageEndpoint: asStr(env['STORAGE_ENDPOINT'], 'http://127.0.0.1:9000'),
    storageRegion: asStr(env['STORAGE_REGION'], 'us-east-1'),
    storageBucket: asStr(env['STORAGE_BUCKET'], 'videos'),
    storageAccessKey: asStr(env['STORAGE_ACCESS_KEY']),
    storageSecretKey: asStr(env['STORAGE_SECRET_KEY']),
    storageForcePathStyle: asBool(env['STORAGE_FORCE_PATH_STYLE'], true),
    apiPort: asInt(env['API_PORT'], 8795),
    apiToken: asStr(env['API_TOKEN']),
    observabilityToken: asStr(env['OBSERVABILITY_TOKEN']),
};
