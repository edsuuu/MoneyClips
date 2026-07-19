import { createMultipartUploader } from './multipart-uploader';

export function videoUploader({ maxBytes, accepted, libraryUrl }) {
    return {
        state: 'idle',
        progress: 0,
        dragging: false,
        error: '',
        libraryUrl,

        get busy() {
            return this.state === 'uploading' || this.state === 'finishing';
        },

        async start(file) {
            if (!file || this.busy) {
                return;
            }

            this.error = '';

            if (!accepted.split(',').includes(file.type)) {
                this.error = 'Formato não suportado — envie MP4, MOV ou WEBM.';
                return;
            }

            if (file.size > maxBytes) {
                this.error = 'O vídeo passa do limite de 3GB.';
                return;
            }

            const upload = createMultipartUploader({
                onProgress: (value) => {
                    this.progress = value;
                },
                onStatus: (value) => {
                    this.state = value;
                },
            });

            try {
                await upload(file);
            } catch (error) {
                this.state = 'idle';
                this.progress = 0;
                this.error = error.message ?? 'Falha ao enviar o vídeo.';
            }
        },

        reset() {
            this.state = 'idle';
            this.progress = 0;
            this.error = '';
            this.$refs.input.value = '';
        },
    };
}
