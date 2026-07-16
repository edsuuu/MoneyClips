// PM2: pnpm build && pm2 start ecosystem.config.cjs
// Roda o bundle gerado pelo tsup (dist/App.js). Concorrência 1 — o Playwright é serial.
module.exports = {
    apps: [
        {
            name: 'tiktok-uploader',
            script: 'dist/App.js',
            cwd: __dirname,
            instances: 1,
            exec_mode: 'fork',
            autorestart: true,
            watch: false,
            time: true,
            // Shutdown drena a fila de posts (um upload leva até ~15 min) —
            // o default de 1,6s mataria o Playwright no meio da publicação.
            kill_timeout: 990_000,
            env: {
                NODE_ENV: 'production',
            },
        },
    ],
};
