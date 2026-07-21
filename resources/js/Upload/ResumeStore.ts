import type { UploadSession } from './UploadTypes';

export class ResumeStore {
    static readonly PREFIX = 'moneyclips.upload.';

    private readonly key: string;

    constructor(file: File) {
        this.key = `${ResumeStore.PREFIX}${file.name}:${file.size}:${file.lastModified}`;
    }

    read(): UploadSession | null {
        try {
            const raw = window.localStorage.getItem(this.key);

            return raw ? (JSON.parse(raw) as UploadSession) : null;
        } catch {
            return null;
        }
    }

    write(session: UploadSession): void {
        try {
            window.localStorage.setItem(this.key, JSON.stringify(session));
        } catch {
            return;
        }
    }

    forget(): void {
        try {
            window.localStorage.removeItem(this.key);
        } catch {
            return;
        }
    }
}
