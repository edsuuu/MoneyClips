import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Landing pública (design docs/designs/Unkvoid.dc.html).
                bunny('Inter', {
                    weights: [400, 500, 600, 700, 800],
                }),
                bunny('JetBrains Mono', {
                    weights: [400, 500],
                }),
            ],
        }),
        tailwindcss(),
    ],
    build: {
        // O hls.js sozinho passa de 500kB e ja sai em chunk proprio, carregado
        // sob demanda pelo HlsPlayer — a entrada fica em ~19kB. O aviso padrao
        // so apontaria pra ele, que e uma lib unica e nao tem como dividir.
        chunkSizeWarningLimit: 600,
    },
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
