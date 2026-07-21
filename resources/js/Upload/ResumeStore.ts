import type { UploadSession } from './UploadTypes';

export class ResumeStore {
    public static readonly PREFIX = 'moneyclips.upload.';

    private readonly key: string;

    public constructor(file: File) {
        this.key = `${ResumeStore.PREFIX}${file.name}:${file.size}:${file.lastModified}`;
    }

    public read(): UploadSession | null {
        try {
            const raw = window.localStorage.getItem(this.key);

            return raw ? (JSON.parse(raw) as UploadSession) : null;
        } catch {
            return null;
        }
    }

    public write(session: UploadSession): void {
        try {
            window.localStorage.setItem(this.key, JSON.stringify(session));
        } catch {
            return;
        }
    }

    public forget(): void {
        try {
            window.localStorage.removeItem(this.key);
        } catch {
            return;
        }
    }
}
