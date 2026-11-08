import { createServer } from 'node:net';
import { spawn } from 'node:child_process';
import { closeSync, existsSync, mkdirSync, mkdtempSync, openSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const root = resolve(import.meta.dirname, '..');
const runs = join(root, '.hrms-e2e');
mkdirSync(runs, { recursive: true });
const run = mkdtempSync(join(runs, 'run-'));
if (!run.startsWith(runs + (process.platform === 'win32' ? '\\' : '/'))) throw new Error('Unsafe E2E run path');
const database = join(run, 'hrms-e2e-test.sqlite');
const mailLog = join(run, 'mail.log');
const serverLog = join(run, 'server.log');
const mysql = process.env.E2E_USE_MYSQL === '1';
if (mysql && (process.env.DB_DATABASE !== 'hrms_e2e_test' || process.env.DB_HOST !== '127.0.0.1')) {
    throw new Error('E2E denied: MySQL mode requires the isolated loopback hrms_e2e_test database.');
}
if (!mysql) writeFileSync(database, '');
const password = randomBytes(24).toString('base64url') + 'Aa1!';
const php = process.env.E2E_PHP || 'php';
const port = await new Promise((resolvePort, reject) => {
    const socket = createServer();
    socket.once('error', reject);
    socket.listen(0, '127.0.0.1', () => {
        const address = socket.address();
        socket.close(() => resolvePort(address.port));
    });
});
const baseURL = `http://127.0.0.1:${port}`;
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: baseURL,
    APP_KEY: 'base64:' + randomBytes(32).toString('base64'), DB_CONNECTION: mysql ? 'mysql' : 'sqlite', DB_URL: '',
    DB_DATABASE: mysql ? 'hrms_e2e_test' : database,
    CACHE_STORE: 'array', SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'log', MAIL_LOG_CHANNEL: 'e2e_mail',
    E2E_MAIL_LOG: mailLog, E2E_PASSWORD: password, E2E_BASE_URL: baseURL, E2E_DB_ID: mysql ? 'hrms_e2e_test' : database,
    APP_CONFIG_CACHE: join(run, 'config.php'), APP_ROUTES_CACHE: join(run, 'routes.php'),
};
const runProcess = (command, args, options = {}) => new Promise((resolveRun, reject) => {
    const child = spawn(command, args, { cwd: root, env, stdio: options.stdio || 'inherit', windowsHide: true });
    child.once('error', reject);
    child.once('exit', (code) => resolveRun(code ?? 1));
});
let server;
let serverLogFd;
try {
    if (await runProcess(php, ['artisan', 'migrate', '--force']) !== 0) throw new Error('E2E migration failed');
    if (await runProcess(php, ['artisan', 'hrms:e2e-fixture']) !== 0) throw new Error('E2E fixture refused');
    serverLogFd = openSync(serverLog, 'w');
    server = spawn(php, ['artisan', 'serve', '--no-reload', '--host=127.0.0.1', `--port=${port}`], { cwd: root, env, stdio: ['ignore', serverLogFd, serverLogFd], windowsHide: true });
    let ready = false;
    let lastHealthError = '';
    for (let i = 0; i < 60; i++) {
        try { const response = await fetch(`${baseURL}/up`); if (response.ok) { ready = true; break; } lastHealthError = `HTTP ${response.status}`; } catch (error) { lastHealthError = String(error); }
        await new Promise((r) => setTimeout(r, 500));
    }
    if (!ready) throw new Error(`E2E server did not become healthy: ${lastHealthError}; server log: ${serverLog}`);
    const code = await runProcess(process.execPath, [join(root, 'node_modules', 'playwright', 'cli.js'), 'test', ...process.argv.slice(2)]);
    process.exitCode = code;
} finally {
    if (server) server.kill();
    if (serverLogFd !== undefined) closeSync(serverLogFd);
    if (existsSync(run) && process.env.E2E_KEEP_RUN !== '1') rmSync(run, { recursive: true, force: true });
    if (process.env.E2E_KEEP_RUN === '1') process.stderr.write(`Fictional E2E run retained locally: ${run}\n`);
}
