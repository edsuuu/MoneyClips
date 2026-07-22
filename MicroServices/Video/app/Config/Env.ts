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
    public readonly apiToken: string;
    public readonly observabilityToken: string;
    public readonly reencodeEnabled: boolean;
    public readonly reencodeBitrateThresholdKbps: number;
    public readonly discordWebhookUrl: string;

    public readonly captionStorageDir: string;
    public readonly transcriberUrl: string;
    public readonly transcriberTimeoutMs: number;
    public readonly maxWordsPerLine: number;
    public readonly maxLineDuration: number;
    public readonly fontName: string;
    public readonly fontSize: number;
    public readonly highlightColor: string;
    public readonly hideFutureWords: boolean;
    public readonly subtitleOffset: number;
    public readonly channelName: string;
    public readonly channelHandle: string;
    public readonly channelLogo: string;
    public readonly templateFontFamily: string;
    public readonly watermarkText: string;
    public readonly outputVariants: string;

    public constructor(env: NodeJS.ProcessEnv = process.env) {
        this.encoder = env['HLS_ENCODER'] === 'cpu' ? 'cpu' : 'gpu';
        this.segmentSeconds = Env.asInt(env['HLS_SEGMENT_SECONDS'], 10);
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

        this.captionStorageDir = Env.asStr(env['CAPTION_STORAGE_DIR'], './storage');
        this.transcriberUrl = Env.asStr(env['TRANSCRIBER_URL'], 'http://127.0.0.1:8780');
        this.transcriberTimeoutMs = Env.asInt(env['TRANSCRIBER_TIMEOUT_MS'], 1_800_000);
        this.maxWordsPerLine = Env.asInt(env['MAX_WORDS_PER_LINE'], 3);
        this.maxLineDuration = Env.asFloat(env['MAX_LINE_DURATION'], 2.5);
        this.fontName = Env.asStr(env['FONT_NAME'], 'Realist Clostan');
        this.fontSize = Env.asInt(env['FONT_SIZE'], 12);
        this.highlightColor = Env.asStr(env['HIGHLIGHT_COLOR'], '&H0000FFFF&');
        this.hideFutureWords = Env.asBool(env['HIDE_FUTURE_WORDS'], true);
        this.subtitleOffset = Env.asFloat(env['SUBTITLE_OFFSET'], 0);
        this.channelName = Env.asStr(env['CHANNEL_NAME'], 'meu_canal');
        this.channelHandle = Env.asStr(env['CHANNEL_HANDLE'], '@meu_canal');
        this.channelLogo = Env.asStr(env['CHANNEL_LOGO'], './assets/logo.png');
        this.templateFontFamily = Env.asStr(env['TEMPLATE_FONT_FAMILY'], 'DejaVu Sans');
        this.watermarkText = Env.asStr(env['WATERMARK_TEXT']);
        this.outputVariants = Env.asStr(
            env['OUTPUT_VARIANTS'],
            'original,vertical,template_white,template_black',
        );
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

    private static asFloat(value: string | undefined, fallback: number): number {
        const parsed = value !== undefined && value.trim() !== '' ? Number(value) : Number.NaN;

        return Number.isFinite(parsed) ? parsed : fallback;
    }
}

export const settings = new Env();
