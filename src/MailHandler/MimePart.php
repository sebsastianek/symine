<?php

declare(strict_types=1);

namespace App\MailHandler;

/**
 * Minimal RFC 2822 / MIME parser (enough for Redmine's MailHandler): folded headers, RFC 2047 encoded
 * words, RFC 2231 parameters, multipart nesting, base64 / quoted-printable transfer encodings and
 * charset conversion to UTF-8. A message is the root part.
 */
final class MimePart
{
    /** @var array<string, list<string>> lowercase header name => raw (unfolded) values */
    private array $headers = [];
    private string $body = '';
    /** @var list<MimePart> */
    private array $parts = [];

    public static function parse(string $raw): self
    {
        $part = new self();
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $pos = strpos($raw, "\n\n");
        if (false === $pos) {
            // headers only (or body only if the first line is not a header)
            [$head, $body] = preg_match('/^[\w-]+:/', $raw) ? [$raw, ''] : ['', $raw];
        } else {
            $head = substr($raw, 0, $pos);
            $body = substr($raw, $pos + 2);
        }
        $head = preg_replace("/\n[ \t]+/", ' ', $head) ?? $head; // unfold
        foreach (explode("\n", $head) as $line) {
            if (preg_match('/^([^:\s]+)\s*:\s?(.*)$/', $line, $m)) {
                $part->headers[strtolower($m[1])][] = rtrim($m[2]);
            }
        }
        $part->body = $body;
        if ($part->isMultipart() && null !== ($boundary = $part->param('content-type', 'boundary'))) {
            $chunks = preg_split('/^--'.preg_quote($boundary, '/').'(--)?[ \t]*$/m', $body) ?: [];
            array_shift($chunks); // preamble
            foreach ($chunks as $i => $chunk) {
                if ($i === \count($chunks) - 1 && '' === trim($chunk)) {
                    continue; // epilogue
                }
                $chunk = preg_replace('/^\n/', '', $chunk) ?? $chunk;
                $chunk = preg_replace('/\n$/', '', $chunk) ?? $chunk;
                $part->parts[] = self::parse($chunk);
            }
        } elseif ('message/rfc822' === $part->mimeType()) {
            // keep the embedded message as an attachment
        }

        return $part;
    }

    /** Raw header value (first occurrence) */
    public function rawHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<string> */
    public function rawHeaders(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /** Decoded (RFC 2047) header value */
    public function header(string $name): ?string
    {
        $v = $this->rawHeader($name);

        return null === $v ? null : self::decodeHeader($v);
    }

    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        // whitespace between adjacent encoded words is ignored
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value) ?? $value;

        return preg_replace_callback('/=\?([^?]+)\?([QqBb])\?([^?]*)\?=/', static function (array $m): string {
            $charset = preg_replace('/\*.*$/', '', $m[1]) ?? $m[1];
            $text = 'B' === strtoupper($m[2])
                ? (string) base64_decode($m[3], false)
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));

            return self::toUtf8($text, $charset);
        }, $value) ?? $value;
    }

    public static function toUtf8(string $text, ?string $charset): string
    {
        $charset = strtolower(trim((string) $charset, " \"'"));
        if ('' === $charset || 'utf-8' === $charset || 'utf8' === $charset || 'us-ascii' === $charset) {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        $aliases = ['iso-8859-8-i' => 'ISO-8859-8', 'ks_c_5601-1987' => 'CP949', 'x-unknown' => 'ISO-8859-1', 'gb2312' => 'GB18030', 'gbk' => 'GB18030'];
        $charset = $aliases[$charset] ?? $charset;
        $out = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $text);
        if (false === $out) {
            try {
                $out = mb_convert_encoding($text, 'UTF-8', $charset);
            } catch (\Throwable) {
                $out = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            }
        }

        return $out;
    }

    /** "text/plain" (lowercase), defaults to text/plain */
    public function mimeType(): string
    {
        $ct = $this->rawHeader('content-type');
        if (null === $ct) {
            return 'text/plain';
        }

        return strtolower(trim(explode(';', $ct, 2)[0]));
    }

    public function isMultipart(): bool
    {
        return str_starts_with($this->mimeType(), 'multipart/');
    }

    public function charset(): ?string
    {
        return $this->param('content-type', 'charset');
    }

    /** Header parameter, with RFC 2231 (name*=charset''value, name*0=...) and RFC 2047 support */
    public function param(string $header, string $name): ?string
    {
        $value = $this->rawHeader($header);
        if (null === $value) {
            return null;
        }
        $params = [];
        if (preg_match_all('/;\s*([^\s=;]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', $value, $m, \PREG_SET_ORDER)) {
            foreach ($m as $p) {
                $v = trim($p[2]);
                if (str_starts_with($v, '"')) {
                    $v = stripcslashes(substr($v, 1, -1));
                }
                $params[strtolower($p[1])] = $v;
            }
        }
        $name = strtolower($name);
        if (isset($params[$name.'*'])) {
            return self::decode2231($params[$name.'*']);
        }
        if (isset($params[$name.'*0']) || isset($params[$name.'*0*'])) {
            $s = '';
            $encoded = false;
            for ($i = 0; isset($params[$name.'*'.$i]) || isset($params[$name.'*'.$i.'*']); ++$i) {
                if (isset($params[$name.'*'.$i.'*'])) {
                    $s .= $params[$name.'*'.$i.'*'];
                    $encoded = true;
                } else {
                    $s .= $params[$name.'*'.$i];
                }
            }

            return $encoded ? self::decode2231($s) : $s;
        }

        return isset($params[$name]) ? self::decodeHeader($params[$name]) : null;
    }

    private static function decode2231(string $v): string
    {
        if (preg_match("/^([^']*)'[^']*'(.*)$/", $v, $m)) {
            return self::toUtf8(rawurldecode($m[2]), $m[1] ?: 'utf-8');
        }

        return rawurldecode($v);
    }

    /** Body after transfer decoding (binary) */
    public function decodedBody(): string
    {
        $enc = strtolower(trim((string) $this->rawHeader('content-transfer-encoding')));

        return match ($enc) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $this->body) ?? '', false),
            'quoted-printable' => quoted_printable_decode(str_replace("\n", "\r\n", $this->body)),
            default => $this->body,
        };
    }

    /** Text body converted to UTF-8 */
    public function text(): string
    {
        return self::toUtf8($this->decodedBody(), $this->charset());
    }

    /** @return list<MimePart> */
    public function parts(): array
    {
        return $this->parts;
    }

    /** @return list<MimePart> all leaf parts, depth first (Mail::Message#all_parts without containers) */
    public function allParts(): array
    {
        $out = [];
        foreach ($this->parts as $p) {
            if ($p->isMultipart()) {
                array_push($out, ...$p->allParts());
            } else {
                $out[] = $p;
            }
        }

        return $out;
    }

    public function filename(): ?string
    {
        $f = $this->param('content-disposition', 'filename') ?? $this->param('content-type', 'name');

        return null === $f || '' === $f ? null : $f;
    }

    /** Mail::Part#attachment? */
    public function isAttachment(): bool
    {
        if (null !== $this->filename()) {
            return true;
        }
        $disp = strtolower(trim(explode(';', (string) $this->rawHeader('content-disposition'))[0]));
        if ('attachment' === $disp) {
            return true;
        }
        $type = $this->mimeType();

        return !str_starts_with($type, 'text/') && !str_starts_with($type, 'multipart/') && null !== $this->rawHeader('content-type') && 'message/delivery-status' !== $type;
    }

    /** @return list<MimePart> */
    public function attachments(): array
    {
        $parts = [] === $this->parts ? [] : $this->allParts();

        return array_values(array_filter($parts, static fn (MimePart $p) => $p->isAttachment()));
    }

    // --- Addresses ------------------------------------------------------------

    /** @return list<array{address: string, name: string|null}> */
    public function addresses(string $header): array
    {
        $out = [];
        foreach ($this->rawHeaders($header) as $raw) {
            foreach (self::splitAddresses($raw) as $item) {
                $item = trim($item);
                if ('' === $item) {
                    continue;
                }
                $name = null;
                if (preg_match('/^(.*)<([^>]*)>\s*(\(.*\))?$/s', $item, $m)) {
                    $name = trim(self::decodeHeader(trim($m[1])), " \"'");
                    $addr = trim($m[2]);
                } else {
                    $addr = $item;
                    if (preg_match('/\(([^)]*)\)/', $item, $c)) {
                        $name = trim(self::decodeHeader($c[1]));
                        $addr = trim((string) preg_replace('/\([^)]*\)/', '', $item));
                    }
                }
                if ('' !== $addr && str_contains($addr, '@')) {
                    $out[] = ['address' => $addr, 'name' => '' === $name ? null : $name];
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function splitAddresses(string $raw): array
    {
        $items = [];
        $buf = '';
        $quoted = false;
        $angle = 0;
        $len = \strlen($raw);
        for ($i = 0; $i < $len; ++$i) {
            $c = $raw[$i];
            if ('"' === $c && ($i < 1 || '\\' !== $raw[$i - 1])) {
                $quoted = !$quoted;
            } elseif (!$quoted && '<' === $c) {
                ++$angle;
            } elseif (!$quoted && '>' === $c) {
                --$angle;
            } elseif (!$quoted && 0 === $angle && (',' === $c || ';' === $c)) {
                $items[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $items[] = $buf;

        return $items;
    }

    /** @return list<string> message ids of In-Reply-To + References */
    public function referencedIds(): array
    {
        $ids = [];
        foreach (['in-reply-to', 'references'] as $h) {
            foreach ($this->rawHeaders($h) as $v) {
                if (preg_match_all('/<([^>]+)>/', $v, $m)) {
                    array_push($ids, ...$m[1]);
                } elseif ('' !== trim($v)) {
                    array_push($ids, ...preg_split('/\s+/', trim($v)) ?: []);
                }
            }
        }

        return $ids;
    }
}
