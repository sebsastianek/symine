<?php

declare(strict_types=1);

namespace App\MailHandler;

/**
 * Tiny IMAP4rev1 client (RFC 3501) - just what Redmine::IMAP.check needs: LOGIN, SELECT, UID SEARCH,
 * UID FETCH RFC822, UID STORE, UID COPY, EXPUNGE, LOGOUT, with implicit TLS or STARTTLS.
 * Pure PHP (streams), so it works without ext-imap (removed from PHP core in 8.4).
 */
final class ImapClient
{
    /** @var resource|null */
    private $stream;
    private int $tag = 0;

    /** @param resource|null $stream injected stream (tests) */
    public function __construct($stream = null)
    {
        $this->stream = $stream;
    }

    public function connect(string $host, int $port = 143, bool $ssl = false, bool $starttls = false, int $timeout = 30, bool $verifyPeer = true): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => $verifyPeer, 'verify_peer_name' => $verifyPeer, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $stream = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://').$host.':'.$port, $errno, $errstr, $timeout, \STREAM_CLIENT_CONNECT, $context);
        if (false === $stream) {
            throw new \RuntimeException("IMAP: cannot connect to $host:$port ($errstr)");
        }
        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new \RuntimeException('IMAP: unexpected greeting: '.trim($greeting));
        }
        if ($starttls) {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($this->stream, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('IMAP: STARTTLS negotiation failed');
            }
        }
    }

    public function login(string $user, string $password): void
    {
        $this->command('LOGIN '.self::quote($user).' '.self::quote($password));
    }

    public function select(string $folder): void
    {
        $this->command('SELECT '.self::quote($folder));
    }

    /** @return list<int> */
    public function uidSearch(string $criteria = 'NOT SEEN'): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH '.$criteria)['untagged'] as $line) {
            if (preg_match('/^\* SEARCH\s*(.*)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $u) {
                    if (ctype_digit($u)) {
                        $uids[] = (int) $u;
                    }
                }
            }
        }

        return $uids;
    }

    public function uidFetchRfc822(int $uid): ?string
    {
        $r = $this->command('UID FETCH '.$uid.' RFC822');

        return $r['literals'][0] ?? null;
    }

    /** @param list<string> $flags e.g. ['\Seen', '\Deleted'] */
    public function uidStore(int $uid, string $mode, array $flags): void
    {
        $this->command('UID STORE '.$uid.' '.$mode.'FLAGS ('.implode(' ', $flags).')');
    }

    public function uidCopy(int $uid, string $folder): void
    {
        $this->command('UID COPY '.$uid.' '.self::quote($folder));
    }

    public function expunge(): void
    {
        $this->command('EXPUNGE');
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
        }
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    /**
     * Sends a tagged command and reads the response until the tagged completion.
     *
     * @return array{untagged: list<string>, literals: list<string>}
     */
    public function command(string $command): array
    {
        $tag = 'A'.str_pad((string) ++$this->tag, 4, '0', \STR_PAD_LEFT);
        $this->write($tag.' '.$command."\r\n");
        $untagged = [];
        $literals = [];
        while (true) {
            $line = $this->readLine();
            // literal {n}
            while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $literals[] = $this->readBytes((int) $m[1]);
                $line = $this->readLine();
            }
            if (str_starts_with($line, $tag.' ')) {
                $status = strtoupper(substr($line, \strlen($tag) + 1, 2));
                if ('OK' !== $status) {
                    throw new \RuntimeException('IMAP: '.preg_replace('/^(A\d+ )/', '', trim($command)).' failed: '.trim(substr($line, \strlen($tag) + 1)));
                }

                return ['untagged' => $untagged, 'literals' => $literals];
            }
            if (str_starts_with($line, '+')) {
                continue;
            }
            $untagged[] = rtrim($line, "\r\n");
        }
    }

    private static function quote(string $s): string
    {
        return '"'.addcslashes($s, '"\\').'"';
    }

    private function write(string $data): void
    {
        if (!\is_resource($this->stream) || false === fwrite($this->stream, $data)) {
            throw new \RuntimeException('IMAP: connection lost');
        }
    }

    private function readLine(): string
    {
        $line = \is_resource($this->stream) ? fgets($this->stream) : false;
        if (false === $line) {
            throw new \RuntimeException('IMAP: connection closed by server');
        }

        return $line;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (\strlen($buf) < $n) {
            $chunk = fread($this->stream, $n - \strlen($buf));
            if (false === $chunk || '' === $chunk) {
                throw new \RuntimeException('IMAP: connection closed while reading a literal');
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
