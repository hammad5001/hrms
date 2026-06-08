/**
 * PM2 — production process manager (cluster + auto-restart)
 *
 *   cd chat-ws && npm install && pm2 start ecosystem.config.js
 *   pm2 save && pm2 startup
 */
module.exports = {
    apps: [
        {
            name: 'balitech-chat-ws',
            script: 'server.js',
            cwd: __dirname,
            instances: process.env.CHAT_WS_INSTANCES || 2,
            exec_mode: 'cluster',
            autorestart: true,
            max_memory_restart: '512M',
            watch: false,
            env: {
                NODE_ENV: 'production',
            },
            error_file: './logs/err.log',
            out_file: './logs/out.log',
            merge_logs: true,
            time: true,
        },
    ],
};
