/**
 * Minimal SMTP server capturing every e-mail Symine sends (MAILER_DSN=smtp://127.0.0.1:<E2E_SMTP_PORT>).
 * Each message is written to PATHS.mail as <time>-<n>.eml, prefixed with X-Envelope-From/To headers.
 * Started by global setup and kept alive for the whole run; read the messages with support/mail.ts.
 */
import * as fs from 'fs';
import * as net from 'net';
import * as path from 'path';

export function startSmtpSink(port: number, dir: string): Promise<net.Server> {
  fs.mkdirSync(dir, { recursive: true });
  let seq = 0;
  const server = net.createServer((socket) => {
    socket.setEncoding('utf8');
    let buffer = '';
    let inData = false;
    let from = '';
    let to: string[] = [];
    const reply = (line: string) => socket.write(line + '\r\n');
    reply('220 symine-e2e ESMTP sink');

    socket.on('data', (chunk: string) => {
      buffer += chunk;
      for (;;) {
        if (inData) {
          const end = buffer.indexOf('\r\n.\r\n');
          if (end === -1) return;
          const body = buffer.slice(2, end).replace(/^\.\./gm, '.');
          buffer = buffer.slice(end + 5);
          inData = false;
          const name = `${Date.now()}-${String(++seq).padStart(4, '0')}.eml`;
          const headers = `X-Envelope-From: ${from}\r\nX-Envelope-To: ${to.join(', ')}\r\n`;
          fs.writeFileSync(path.join(dir, name + '.part'), headers + body + '\r\n');
          fs.renameSync(path.join(dir, name + '.part'), path.join(dir, name));
          from = '';
          to = [];
          reply('250 OK queued');
          continue;
        }
        const eol = buffer.indexOf('\r\n');
        if (eol === -1) return;
        const line = buffer.slice(0, eol);
        buffer = buffer.slice(eol + 2);
        const cmd = line.slice(0, 4).toUpperCase();
        if (cmd === 'EHLO') reply('250-symine-e2e\r\n250-8BITMIME\r\n250 SMTPUTF8');
        else if (cmd === 'HELO') reply('250 symine-e2e');
        else if (cmd === 'MAIL') {
          from = (line.match(/<([^>]*)>/) || [])[1] || '';
          reply('250 OK');
        } else if (cmd === 'RCPT') {
          to.push((line.match(/<([^>]*)>/) || [])[1] || '');
          reply('250 OK');
        } else if (cmd === 'DATA') {
          inData = true;
          buffer = '\r\n' + buffer; // so that an empty body still ends with <CRLF>.<CRLF>
          reply('354 End data with <CR><LF>.<CR><LF>');
        } else if (cmd === 'QUIT') {
          reply('221 Bye');
          socket.end();
          return;
        } else if (cmd === 'RSET') {
          from = '';
          to = [];
          reply('250 OK');
        } else reply('250 OK');
      }
    });
    socket.on('error', () => undefined);
  });
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', () => resolve(server));
  });
}
