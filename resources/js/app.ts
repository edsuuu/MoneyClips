import { HlsPlayer } from './Player/HlsPlayer';
import { ReframeEditor } from './Reframe/ReframeEditor';
import { ClientLogger } from './Support/ClientLogger';
import { MultipartUploader } from './Upload/MultipartUploader';
import { VideoUploader } from './Upload/VideoUploader';
import type { ReframePayload } from './Reframe/ReframeTypes';
import type { VideoUploaderConfig } from './Upload/UploadTypes';

declare global {
    interface Window {
        Alpine: { data: (name: string, factory: (...args: never[]) => unknown) => void };
        Hls?: unknown;
        ClientLogger: typeof ClientLogger;
        HlsPlayer: typeof HlsPlayer;
        MultipartUploader: typeof MultipartUploader;
        ReframeEditor: typeof ReframeEditor;
        VideoUploader: typeof VideoUploader;
        initAdaptiveVideoPlayer: (element: HTMLVideoElement) => void;
    }
}

window.ClientLogger = ClientLogger;
window.HlsPlayer = HlsPlayer;
window.MultipartUploader = MultipartUploader;
window.ReframeEditor = ReframeEditor;
window.VideoUploader = VideoUploader;

window.initAdaptiveVideoPlayer = (element: HTMLVideoElement): void => {
    HlsPlayer.attach(element).catch((error: unknown) => {
        ClientLogger.send('error', `Player HLS falhou ao montar: ${String(error)}`);
    });
};

ClientLogger.install();

document.addEventListener('alpine:init', () => {
    window.Alpine.data('reframeEditor', (initial) => new ReframeEditor(initial as unknown as ReframePayload));
    window.Alpine.data('videoUploader', (config) => new VideoUploader(config as unknown as VideoUploaderConfig));
});
