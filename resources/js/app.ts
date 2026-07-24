import { VideoPlayer, type VideoPlayerConfig } from './Player/VideoPlayer';
import { ReframeEditor } from './Reframe/ReframeEditor';
import type { ReframePayload } from './Reframe/ReframeTypes';
import { SubtitleEditor, type RawSegment } from './Subtitle/SubtitleEditor';
import { ClientLogger } from './Support/ClientLogger';
import { ThemeStore } from './Support/ThemeStore';
import { TrimEditor, type TrimEditorConfig } from './Trim/TrimEditor';
import { MultipartUploader } from './Upload/MultipartUploader';
import type { VideoUploaderConfig } from './Upload/UploadTypes';
import { VideoUploader } from './Upload/VideoUploader';

declare global {
    interface Window {
        Alpine: {
            data: (name: string, factory: (...args: never[]) => unknown) => void;
            store: (name: string, value: unknown) => void;
        };
        Hls?: unknown;
        ClientLogger: typeof ClientLogger;
        MultipartUploader: typeof MultipartUploader;
        ReframeEditor: typeof ReframeEditor;
        VideoUploader: typeof VideoUploader;
    }
}

window.ClientLogger = ClientLogger;
window.MultipartUploader = MultipartUploader;
window.ReframeEditor = ReframeEditor;
window.VideoUploader = VideoUploader;

ClientLogger.install();

document.addEventListener('alpine:init', () => {
    window.Alpine.store('theme', new ThemeStore());
    window.Alpine.data(
        'reframeEditor',
        (initial) => new ReframeEditor(initial as unknown as ReframePayload),
    );
    window.Alpine.data(
        'videoUploader',
        (config) => new VideoUploader(config as unknown as VideoUploaderConfig),
    );
    window.Alpine.data(
        'videoPlayer',
        (config) => new VideoPlayer(config as unknown as VideoPlayerConfig),
    );
    window.Alpine.data(
        'subtitleEditor',
        (segments) => new SubtitleEditor(segments as unknown as RawSegment[]),
    );
    window.Alpine.data(
        'trimEditor',
        (config) => new TrimEditor(config as unknown as TrimEditorConfig),
    );
});
