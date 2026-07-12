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

export interface Settings {
    roboflowApiKey: string;
    showBrowser: boolean;
    dryRun: boolean;
    discordWebhookUrl: string;
    discordUserNotifier: string;
    apiPort: number;
}

const env = process.env;

export const settings: Settings = {
    roboflowApiKey: asStr(env['ROBOFLOW_API_KEY']),
    showBrowser: asBool(env['SHOW_BROWSER'], false),
    dryRun: asBool(env['DRY_RUN'], true),
    discordWebhookUrl: asStr(env['DISCORD_WEBHOOK_URL']),
    discordUserNotifier: asStr(env['DISCORD_USER_NOTIFIER']),
    apiPort: asInt(env['API_PORT'], 8090),
};
