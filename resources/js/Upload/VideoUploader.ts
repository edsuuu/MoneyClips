import { MultipartUploader } from './MultipartUploader';
import type { UploadState, VideoUploaderConfig } from './UploadTypes';

export class VideoUploader {
    public state: UploadState = 'idle';

    public progress = 0;

    public dragging = false;

    public error = '';

    public $refs!: Record<string, HTMLInputElement | undefined>;

    private readonly maxBytes: number;

    private readonly accepted: string;

    private readonly videoUrlBase: string;

    public constructor({ maxBytes, accepted, videoUrlBase }: VideoUploaderConfig) {
        this.maxBytes = maxBytes;
        this.accepted = accepted;
        this.videoUrlBase = videoUrlBase;
    }

    public get busy(): boolean {
        return this.state === 'uploading' || this.state === 'finishing';
    }

    public async start(file: File | undefined): Promise<void> {
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
            const result = await new MultipartUploader({
                onProgress: (value) => {
                    this.progress = value;
                },
                onStatus: (value) => {
                    this.state = value;
                },
            }).upload(file);

            window.location.href = `${this.videoUrlBase}/${result.video_uuid}`;
        } catch (error) {
            this.state = 'idle';
            this.progress = 0;
            this.error = (error as Error).message || 'Falha ao enviar o vídeo.';
        }
    }

    public reset(): void {
        this.state = 'idle';
        this.progress = 0;
        this.error = '';

        const input = this.$refs.input;

        if (input) {
            input.value = '';
        }
    }
}
