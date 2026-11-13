#!/usr/bin/env node
// webServer launcher (see playwright.config.ts): prepares a fresh e2e DB + files dir, then runs Symine with PHP's
// built-in server. Playwright starts the webServer *before* globalSetup, so the DB must exist before the first request.
// All paths come from the environment set by playwright.config.ts.
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const env = process.env;
const db = env.E2E_DB_PATH;
for (const ext of ['', '-journal', '-wal', '-shm']) fs.rmSync(db + ext, { force: true });
fs.mkdirSync(path.dirname(db), { recursive: true });
fs.copyFileSync(env.E2E_FIXTURE_DB, db);
fs.rmSync(env.SYMINE_FILES_PATH, { recursive: true, force: true });
fs.mkdirSync(env.SYMINE_FILES_PATH, { recursive: true });
if (fs.existsSync(env.E2E_SEED_FILES)) fs.cpSync(env.E2E_SEED_FILES, env.SYMINE_FILES_PATH, { recursive: true });

fs.rmSync(env.E2E_SESSIONS_PATH, { recursive: true, force: true });
fs.mkdirSync(env.E2E_SESSIONS_PATH, { recursive: true });

const log = fs.openSync(env.E2E_SERVER_LOG, 'w');
const php = spawn(
  env.E2E_PHP,
  ['-d', 'variables_order=EGPCS', '-d', `session.save_path=${env.E2E_SESSIONS_PATH}`, '-d', 'session.gc_maxlifetime=604800',
    '-d', 'memory_limit=512M', '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=25M',
    '-S', `127.0.0.1:${env.E2E_PORT}`, '-t', 'public'],
  { cwd: env.E2E_ROOT, env, stdio: ['ignore', log, log] },
);
const stop = () => php.kill('SIGTERM');
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
php.on('exit', (code) => process.exit(code ?? 0));
