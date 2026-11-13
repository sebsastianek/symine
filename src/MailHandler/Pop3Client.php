<?php

declare(strict_types=1);

namespace App\MailHandler;

/**
 * Tiny POP3 client (RFC 1939) - what Redmine::POP3.check needs (Net::POP3): USER/PASS or APOP,
 * STAT, UIDL-less LIST, RETR, DELE, QUIT, with implicit TLS (POP3S). Pure PHP streams.
 */
final class Pop3Client
{
    /** @var resource|null */
    private $stream;
    private string $greeting = '';

    /** @param resource|null $stream injected stream (tests) */
    public function __construct($stream = null)
    {
        $this->stream = $stream;
        if (null !== $stream) {
            $this->greeting = $this->response();
        }
    }

    public function connect(string $host, int $port = 110, bool $ssl = false, int $timeout = 30, bool $verifyPeer = true): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => $verifyPeer, 'verify_peer_name' => $verifyPeer, 'allow_self_signed' => !$verifyPeer, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $stream = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://').$host.':'.$port, $errno, $errstr, $timeout, \STREAM_CLIENT_CONNECT, $context);
        if (false === $stream) {
            throw new \RuntimeException("POP3: cannot connect to $host:$port ($errstr)");
        }
        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;
        $this->greeting = $this->response();
    }

    /** USER/PASS authentication, or APOP (MD5 of the greeting timestamp + password) */
    public function login(string $user, string $password, bool $apop = false): void
    {
        if ($apop) {
            if (!preg_match('/<[^>]+>/', $this->greeting, $m)) {
                throw new \RuntimeException('POP3: the server does not support APOP');
            }
            $this->command('APOP '.$user.' '.md5($m[0].$password));

            return;
        }
        $this->command('USER '.$user);
        $this->command('PASS '.$password);
    }

    /** @return list<int> message numbers (LIST) */
    public function messageNumbers(): array
    {
        $this->command('LIST');
        $numbers = [];
        foreach ($this->multiline() as $line) {
            if (preg_match('/^(\d+)\s+\d+/', $line, $m)) {
                $numbers[] = (int) $m[1];
            }
        }

        return $numbers;
    }

    public function retrieve(int $number): string
    {
        $this->command('RETR '.$number);

        return implode("\r\n", $this->multiline())."\r\n";
    }

    public function delete(int $number): void
    {
        $this->command('DELE '.$number);
    }

    /** QUIT commits the deletions (UPDATE state) */
    public function quit(): void
    {
        try {
            $this->command('QUIT');
        } finally {
            if (\is_resource($this->stream)) {
                fclose($this->stream);
            }
            $this->stream = null;
        }
    }

    /** Closes the connection without QUIT: messages flagged for deletion are kept */
    public function disconnect(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    public function command(string $command): string
    {
        if (!\is_resource($this->stream) || false === fwrite($this->stream, $command."\r\n")) {
            throw new \RuntimeException('POP3: connection lost');
        }

        return $this->response(preg_replace('/^(PASS|APOP \S+) .*/', '$1 ***', $command));
    }

    private function response(?string $command = null): string
    {
        $line = $this->readLine();
        if (!str_starts_with($line, '+OK')) {
            throw new \RuntimeException('POP3: '.(null !== $command ? $command.' failed: ' : '').trim($line));
        }

        return $line;
    }

    /** @return list<string> the lines of a multi-line response, dot-unstuffed */
    private function multiline(): array
    {
        $lines = [];
        while (true) {
            $line = rtrim($this->readLine(), "\r\n");
            if ('.' === $line) {
                return $lines;
            }
            $lines[] = str_starts_with($line, '..') ? substr($line, 1) : $line;
        }
    }

    private function readLine(): string
    {
        $line = \is_resource($this->stream) ? fgets($this->stream) : false;
        if (false === $line) {
            throw new \RuntimeException('POP3: connection closed by server');
        }

        return $line;
    }
}
