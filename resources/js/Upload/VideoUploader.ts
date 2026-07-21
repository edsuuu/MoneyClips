import { MultipartUploader } from './MultipartUploader';
import type { UploadState, VideoUploaderConfig } from './UploadTypes';

export class VideoUploader {
    readonly libraryUrl: string;

    state: UploadState = 'idle';

    progress = 0;

    dragging = false;

    error = '';

    $refs!: Record<string, HTMLInputElement | undefined>;

    private readonly maxBytes: number;

    private readonly accepted: string;

    constructor({ maxBytes, accepted, libraryUrl }: VideoUploaderConfig) {
        this.maxBytes = maxBytes;
        this.accepted = accepted;
        this.libraryUrl = libraryUrl;
    }

    get busy(): boolean {
        return this.state === 'uploading' || this.state === 'finishing';
    }

    async start(file: File | undefined): Promise<void> {
        if (!file || this.busy) {
            return;
        }

        this.error = '';

        if (!this.accepted.split(',').includes(file.type)) {
            this.error = 'Formato não suportado — envie MP4, MOV ou WEBM.';

            return;
        }

        if (file.size > this.maxBytes) {
            this.error = 'O vídeo passa do limite de 3GB.';

            return;
        }

        try {
            await new MultipartUploader({
                onProgress: (value) => {
                    this.progress = value;
                },
                onStatus: (value) => {
                    this.state = value;
                },
            }).upload(file);
        } catch (error) {
            this.state = 'idle';
            this.progress = 0;
            this.error = (error as Error).message || 'Falha ao enviar o vídeo.';
        }
    }

    reset(): void {
        this.state = 'idle';
        this.progress = 0;
        this.error = '';

        const input = this.$refs.input;

        if (input) {
            input.value = '';
        }
    }
}
