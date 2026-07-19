import { reframeEditor } from './reframe-editor';
import { videoUploader } from './video-uploader';
import './hls-player';

// Registrado antes do Alpine embutido do Livewire subir (app.js carrega no
// <head>; @livewireScripts só no fim do <body>).
document.addEventListener('alpine:init', () => {
    window.Alpine.data('reframeEditor', reframeEditor);
    window.Alpine.data('videoUploader', videoUploader);

    // O <head> já aplicou a classe pré-paint; aqui só espelhamos o estado.
    window.Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),
        sync() {
            this.dark = document.documentElement.classList.contains('dark');
        },
        toggle() {
            const root = document.documentElement;

            root.classList.add('theme-switching');
            this.dark = !this.dark;
            localStorage.theme = this.dark ? 'dark' : 'light';
            root.classList.toggle('dark', this.dark);

            // Reflow síncrono: aplica as cores novas ainda com transition:none.
            // (rAF não serve — não dispara em aba de fundo e a classe ficaria presa.)
            void root.offsetHeight;
            root.classList.remove('theme-switching');
        },
    });
});

// wire:navigate troca o <body> sem reexecutar o script inline do <head>, então
// a classe do tema precisa ser reaplicada a cada navegação.
document.addEventListener('livewire:navigated', () => {
    window.applyStoredTheme?.();
    window.Alpine?.store('theme')?.sync();
});
