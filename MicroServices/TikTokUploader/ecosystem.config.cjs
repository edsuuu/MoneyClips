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
            env: {
                NODE_ENV: 'production',
            },
        },
    ],
};
