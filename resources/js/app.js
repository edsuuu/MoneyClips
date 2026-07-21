import { reframeEditor } from './reframe-editor';
import { videoUploader } from './video-uploader';
import './hls-player';

// Registrado antes do Alpine embutido do Livewire subir (app.js carrega no
// <head>; @livewireScripts só no fim do <body>).
document.addEventListener('alpine:init', () => {
    window.Alpine.data('reframeEditor', reframeEditor);
    window.Alpine.data('videoUploader', videoUploader);
});
