/**
 * Reads the e-mails captured by the SMTP sink (support/smtp-sink.ts).
 *
 *   const msg = await mailbox.waitFor({ to: 'jsmith@somenet.foo', subject: /Lost password/ });
 *   const link = msg.links().find((l) => l.includes('/account/lost_password?token='));
 *
 * The `mailbox` fixture only sees messages received after the test started.
 */
import { expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { PATHS } from './env';

export interface MailMessage {
  file: string;
  receivedAt: number;
  envelopeTo: string[];
  headers: Record<string, string>;
  subject: string;
  to: string;
  text: string;
  html: string;
  raw: string;
  /** Absolute URLs found in the text and html parts. */
  links(): string[];
}

export interface MailFilter {
  to?: string | RegExp;
  subject?: string | RegExp;
  body?: string | RegExp;
}

function decodeWords(value: string): string {
  return value.replace(/=\?([^?]+)\?([QBqb])\?([^?]*)\?=\s*/g, (_m, charset: string, enc: string, data: string) => {
    const buf =
      enc.toUpperCase() === 'B'
        ? Buffer.from(data, 'base64')
        : Buffer.from(qpBytes(data.replace(/_/g, ' ')));
    return buf.toString(/utf-?8/i.test(charset) ? 'utf8' : 'latin1');
  });
}

function qpBytes(s: string): number[] {
  const bytes: number[] = [];
  const src = s.replace(/=\r?\n/g, '');
  for (let i = 0; i < src.length; i++) {
    if (src[i] === '=' && /^[0-9A-F]{2}$/i.test(src.slice(i + 1, i + 3))) {
      bytes.push(parseInt(src.slice(i + 1, i + 3), 16));
      i += 2;
    } else {
      bytes.push(...Buffer.from(src[i], 'utf8'));
    }
  }
  return bytes;
}

function parseHeaders(block: string): Record<string, string> {
  const headers: Record<string, string> = {};
  for (const line of block.replace(/\r?\n[ \t]+/g, ' ').split(/\r?\n/)) {
    const i = line.indexOf(':');
    if (i > 0) headers[line.slice(0, i).trim().toLowerCase()] = line.slice(i + 1).trim();
  }
  return headers;
}

function splitPart(raw: string): { headers: Record<string, string>; body: string } {
  const sep = raw.search(/\r?\n\r?\n/);
  if (sep === -1) return { headers: parseHeaders(raw), body: '' };
  const m = raw.slice(sep).match(/^\r?\n\r?\n/)!;
  return { headers: parseHeaders(raw.slice(0, sep)), body: raw.slice(sep + m[0].length) };
}

function decodeBody(headers: Record<string, string>, body: string): string {
  const enc = (headers['content-transfer-encoding'] || '').toLowerCase();
  if (enc === 'base64') return Buffer.from(body.replace(/\s+/g, ''), 'base64').toString('utf8');
  if (enc === 'quoted-printable') return Buffer.from(qpBytes(body)).toString('utf8');
  return body;
}

function collectParts(headers: Record<string, string>, body: string, out: { text: string; html: string }): void {
  const type = headers['content-type'] || 'text/plain';
  const boundary = type.match(/boundary="?([^";]+)"?/i)?.[1];
  if (/^multipart\//i.test(type) && boundary) {
    for (const chunk of body.split(`--${boundary}`).slice(1)) {
      if (chunk.startsWith('--')) break;
      const part = splitPart(chunk.replace(/^\r?\n/, ''));
      collectParts(part.headers, part.body, out);
    }
  } else if (/^text\/html/i.test(type)) out.html += decodeBody(headers, body);
  else if (/^text\//i.test(type)) out.text += decodeBody(headers, body);
}

export function parseMail(file: string): MailMessage {
  const raw = fs.readFileSync(file, 'utf8');
  const { headers, body } = splitPart(raw);
  const parts = { text: '', html: '' };
  collectParts(headers, body, parts);
  const msg: MailMessage = {
    file,
    receivedAt: fs.statSync(file).mtimeMs,
    envelopeTo: (headers['x-envelope-to'] || '').split(/,\s*/).filter(Boolean),
    headers,
    subject: decodeWords(headers['subject'] || ''),
    to: decodeWords(headers['to'] || ''),
    text: parts.text,
    html: parts.html,
    raw,
    links() {
      const found = new Set<string>();
      for (const m of (parts.text + '\n' + parts.html.replace(/&amp;/g, '&')).matchAll(/https?:\/\/[^\s"'<>)]+/g)) found.add(m[0]);
      return [...found];
    },
  };
  return msg;
}

function matches(value: string | string[], f: string | RegExp | undefined): boolean {
  if (f === undefined) return true;
  const values = Array.isArray(value) ? value : [value];
  return values.some((v) => (typeof f === 'string' ? v.includes(f) : f.test(v)));
}

export class Mailbox {
  constructor(private since = 0) {}

  /** Forget everything received so far (only later messages are returned). */
  clear(): void {
    this.since = Date.now();
  }

  all(): MailMessage[] {
    if (!fs.existsSync(PATHS.mail)) return [];
    return fs
      .readdirSync(PATHS.mail)
      .filter((f) => f.endsWith('.eml') && Number(f.split('-')[0]) >= this.since)
      .sort()
      .map((f) => parseMail(path.join(PATHS.mail, f)));
  }

  find(filter: MailFilter = {}): MailMessage[] {
    return this.all().filter(
      (m) =>
        matches([...m.envelopeTo, m.to], filter.to) &&
        matches(m.subject, filter.subject) &&
        matches([m.text, m.html], filter.body),
    );
  }

  /** Polls until a matching message arrives (mails are sent after the response, in kernel.terminate). */
  async waitFor(filter: MailFilter = {}, timeout = 10_000): Promise<MailMessage> {
    let found: MailMessage | undefined;
    await expect
      .poll(() => (found = this.find(filter).at(-1)) !== undefined, {
        message: `e-mail matching ${JSON.stringify(filter, (_k, v) => (v instanceof RegExp ? String(v) : v))}`,
        timeout,
      })
      .toBe(true);
    return found!;
  }
}
