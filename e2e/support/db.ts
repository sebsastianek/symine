/**
 * Direct access to the e2e SQLite database (through the sqlite3 CLI, no native node module needed).
 *
 * - `prepareDatabase()`  fresh copy of tests/fixtures/symine-seeded.db + attachments dir (used by the server launcher
 *                         and global setup).
 * - `snapshotDatabase()` saves the current DB as the "pristine" state (taken by global setup *after* the users
 *                         logged in, so stored sessions stay valid after a reset).
 * - `resetDatabase()`    restores the pristine DB, the attachments dir and the PHP session files (called
 *                         automatically before each spec file, see fixtures.ts).
 * - `sql()` / `sqlValue()` query helpers for assertions (e.g. tokens created by "lost password").
 */
import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { BASE_URL, IS_EXTERNAL, PATHS } from './env';

const SQLITE = process.env.E2E_SQLITE || 'sqlite3';

function removeDbFiles(db: string): void {
  for (const ext of ['', '-journal', '-wal', '-shm']) fs.rmSync(db + ext, { force: true });
}

function copyDir(src: string, dst: string): void {
  fs.rmSync(dst, { recursive: true, force: true });
  fs.mkdirSync(dst, { recursive: true });
  if (fs.existsSync(src)) fs.cpSync(src, dst, { recursive: true });
}

/** Fresh DB from the seeded fixture, attachments dir reset to the seeded files. */
export function prepareDatabase(): void {
  fs.mkdirSync(path.dirname(PATHS.db), { recursive: true });
  removeDbFiles(PATHS.db);
  fs.copyFileSync(PATHS.fixtureDb, PATHS.db);
  copyDir(PATHS.seedFiles, PATHS.files);
}

export function snapshotDatabase(): void {
  // server-side sessions of the stored logins (session.save_path of the e2e server)
  if (fs.existsSync(PATHS.sessions)) copyDir(PATHS.sessions, PATHS.pristineSessions);
  removeDbFiles(PATHS.pristineDb);
  // VACUUM INTO gives a consistent copy even if the server holds a connection
  execFileSync(SQLITE, [PATHS.db, `VACUUM INTO '${PATHS.pristineDb.replace(/'/g, "''")}'`]);
}

/**
 * Waits until the (single-worker) PHP server is idle: the built-in server handles requests one by one, so once
 * this request is answered every earlier request (including kernel.terminate work like e-mails) has finished.
 */
async function drainServer(): Promise<void> {
  try {
    await fetch(`${BASE_URL}/up`, { signal: AbortSignal.timeout(30_000) });
  } catch {
    /* server not up yet: nothing to drain */
  }
}

export async function resetDatabase(): Promise<void> {
  if (IS_EXTERNAL) throw new Error('resetDatabase() is not available with E2E_BASE_URL (external server)');
  if (!fs.existsSync(PATHS.pristineDb)) throw new Error(`No pristine DB at ${PATHS.pristineDb} (global setup did not run?)`);
  await drainServer();
  const tmp = `${PATHS.db}.tmp`;
  fs.copyFileSync(PATHS.pristineDb, tmp);
  for (const ext of ['-journal', '-wal', '-shm']) fs.rmSync(PATHS.db + ext, { force: true });
  fs.renameSync(tmp, PATHS.db); // atomic replace: a request opening the DB sees either the old or the new file
  copyDir(PATHS.seedFiles, PATHS.files);
  // sessions too: a spec that logs out / re-authenticates a stored user must not affect later files
  if (fs.existsSync(PATHS.pristineSessions)) copyDir(PATHS.pristineSessions, PATHS.sessions);
}

export type Row = Record<string, string | number | null>;

/** Runs a query and returns rows as objects. Use `?` placeholders with params (values are SQL-quoted). */
export function sql(query: string, params: Array<string | number | null | boolean> = []): Row[] {
  if (IS_EXTERNAL) throw new Error('sql() is not available with E2E_BASE_URL (external server)');
  let i = 0;
  const bound = query.replace(/\?/g, () => quote(params[i++]));
  const out = execFileSync(SQLITE, ['-json', '-cmd', '.timeout 10000', PATHS.db, bound], { encoding: 'utf8' });
  return out.trim() === '' ? [] : (JSON.parse(out) as Row[]);
}

/** First column of the first row (or null). */
export function sqlValue(query: string, params: Array<string | number | null | boolean> = []): string | number | null {
  const row = sql(query, params)[0];
  return row ? Object.values(row)[0] : null;
}

function quote(v: string | number | null | boolean | undefined): string {
  if (v === null || v === undefined) return 'NULL';
  if (typeof v === 'boolean') return v ? '1' : '0';
  if (typeof v === 'number') return String(v);
  return `'${v.replace(/'/g, "''")}'`;
}
