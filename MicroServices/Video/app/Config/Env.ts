import 'dotenv/config';

export class Env {
    private static readonly TRUTHY = new Set(['1', 'true', 'yes', 'on', 'sim']);

    public readonly encoder: 'gpu' | 'cpu';
    public readonly segmentSeconds: number;
    public readonly workDir: string;
    public readonly storageEndpoint: string;
    public readonly storageRegion: string;
    public readonly storageBucket: string;
    public readonly storageAccessKey: string;
    public readonly storageSecretKey: string;
    public readonly storageForcePathStyle: boolean;
    public readonly apiPort: number;
    /** Vazio = endpoints abertos (dev); setado = exige Bearer token. */
    public readonly apiToken: string;
    /** Compartilhado com o Laravel — autentica o webhook do desfecho (fail-closed do lado de lá). */
    public readonly observabilityToken: string;
    public readonly reencodeEnabled: boolean;
    public readonly reencodeBitrateThresholdKbps: number;
    public readonly discordWebhookUrl: string;

    public constructor(env: NodeJS.ProcessEnv = process.env) {
        this.encoder = env['HLS_ENCODER'] === 'cpu' ? 'cpu' : 'gpu';
        this.segmentSeconds = Env.asInt(env['HLS_SEGMENT_SECONDS'], 6);
        this.workDir = Env.asStr(env['HLS_WORK_DIR']);
        this.storageEndpoint = Env.asStr(env['STORAGE_ENDPOINT'], 'http://127.0.0.1:9000');
        this.storageRegion = Env.asStr(env['STORAGE_REGION'], 'us-east-1');
        this.storageBucket = Env.asStr(env['STORAGE_BUCKET'], 'videos');
        this.storageAccessKey = Env.asStr(env['STORAGE_ACCESS_KEY']);
        this.storageSecretKey = Env.asStr(env['STORAGE_SECRET_KEY']);
        this.storageForcePathStyle = Env.asBool(env['STORAGE_FORCE_PATH_STYLE'], true);
        this.apiPort = Env.asInt(env['API_PORT'], 8790);
        this.apiToken = Env.asStr(env['API_TOKEN']);
        this.observabilityToken = Env.asStr(env['OBSERVABILITY_TOKEN']);
        this.reencodeEnabled = Env.asBool(env['REENCODE_ENABLED'], true);
        this.reencodeBitrateThresholdKbps = Env.asInt(env['REENCODE_BITRATE_THRESHOLD_KBPS'], 4000);
        this.discordWebhookUrl = Env.asStr(env['DISCORD_WEBHOOK_URL']);
    }

    private static asBool(value: string | undefined, fallback: boolean): boolean {
        if (value === undefined || value.trim() === '') {
            return fallback;
        }

        return Env.TRUTHY.has(value.trim().toLowerCase());
    }

    private static asStr(value: string | undefined, fallback = ''): string {
        return value === undefined || value === '' ? fallback : value;
    }

    private static asInt(value: string | undefined, fallback: number): number {
        const parsed = value !== undefined && value.trim() !== '' ? Number(value) : Number.NaN;

        return Number.isInteger(parsed) ? parsed : fallback;
    }
}

export const settings = new Env();
