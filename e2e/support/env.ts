/**
 * Shared configuration of the e2e suite (paths, ports, users).
 *
 * Every path is namespaced by E2E_TOKEN so several suites (e.g. other agents) can run in parallel on the same
 * checkout: `E2E_TOKEN=pdf E2E_PORT=8771 bin/test-e2e tests/pdf`.
 */
import * as path from 'path';

export const ROOT = path.resolve(__dirname, '..', '..');
export const VAR = path.join(ROOT, 'var');
export const PHP = process.env.E2E_PHP || '/opt/homebrew/opt/php/bin/php';

const token = (process.env.E2E_TOKEN || '').replace(/[^a-zA-Z0-9_-]/g, '');
const suffix = token ? `-${token}` : '';

export const PORT = Number(process.env.E2E_PORT || 8765);
export const SMTP_PORT = Number(process.env.E2E_SMTP_PORT || PORT + 1000);

/** When E2E_BASE_URL is set the suite runs against that server (no DB reset, no mail capture). */
export const EXTERNAL_BASE_URL = process.env.E2E_BASE_URL?.replace(/\/$/, '') || '';
export const BASE_URL = EXTERNAL_BASE_URL || `http://127.0.0.1:${PORT}`;
export const IS_EXTERNAL = EXTERNAL_BASE_URL !== '';

export const PATHS = {
  fixtureDb: path.join(ROOT, 'tests', 'fixtures', 'symine-seeded.db'),
  seedFiles: path.join(VAR, 'redmine-src', 'files'),
  db: path.join(VAR, `e2e${suffix}.db`),
  pristineDb: path.join(VAR, `e2e${suffix}-pristine.db`),
  files: path.join(VAR, `files-e2e${suffix}`),
  cache: path.join(VAR, `cache-e2e${suffix}`),
  mail: path.join(VAR, `e2e${suffix}-mail`),
  serverLog: path.join(VAR, `e2e${suffix}-server.log`),
  /** PHP session files of the e2e server (session.save_path), restored with the DB before each spec file. */
  sessions: path.join(VAR, `e2e${suffix}-sessions`),
  pristineSessions: path.join(VAR, `e2e${suffix}-sessions-pristine`),
  /** storageState files + api keys; per target host so dual-run against the reference does not mix sessions. */
  auth: path.join(VAR, `e2e${suffix}-auth`, IS_EXTERNAL ? new URL(BASE_URL).host.replace(/[^a-z0-9]/gi, '_') : 'symine'),
  report: path.join(VAR, `e2e${suffix}-report`),
  results: path.join(VAR, `e2e${suffix}-results`),
};

/** Seeded users (same logins/passwords on Symine fixture and on the reference Redmine). */
export const USERS = {
  admin: { login: 'admin', password: 'admin', id: 1, name: 'Redmine Admin' },
  jsmith: { login: 'jsmith', password: 'password123', id: 5, name: 'John Smith' },
  dlopper: { login: 'dlopper', password: 'password123', id: 6, name: 'Dave Lopper' },
  rhill: { login: 'rhill', password: 'password123', id: 7, name: 'Robert Hill' },
  anna: { login: 'anna', password: 'password123', id: 9, name: 'Anna Müller' },
} as const;

export type UserLogin = keyof typeof USERS;
export const USER_LOGINS = Object.keys(USERS) as UserLogin[];

export function authFile(login: UserLogin): string {
  return path.join(PATHS.auth, `${login}.json`);
}

export const API_KEYS_FILE = () => path.join(PATHS.auth, 'api-keys.json');
