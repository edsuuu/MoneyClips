export class TikTokContentRestrictionError extends Error {
    public constructor(details: string) {
        super(`Vídeo restringido pelo TikTok: ${details}`);
        this.name = 'TikTokContentRestrictionError';
    }
}
