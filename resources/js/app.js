import { ClientLogger } from './ClientLogger';
import { reframeEditor } from './ReframeEditor';
import { videoUploader } from './VideoUploader';
import './HlsPlayer';

ClientLogger.install();

document.addEventListener('alpine:init', () => {
    window.Alpine.data('reframeEditor', reframeEditor);
    window.Alpine.data('videoUploader', videoUploader);
});
