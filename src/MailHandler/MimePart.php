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
        if ('utf-8' === $charset || 'utf8' === $charset) {
            // Redmine::CodesetUtil.replace_invalid_utf8: invalid bytes become "?", the rest of the text is kept
            return mb_check_encoding($text, 'UTF-8') ? $text : self::replaceInvalidUtf8($text);
        }
        if ('' === $charset || 'us-ascii' === $charset) {
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

    private static function replaceInvalidUtf8(string $text): string
    {
        $previous = mb_substitute_character();
        mb_substitute_character(0x3F);
        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
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

    /**
     * Mail::Message#find_attachment: the file name of the part, taken from Content-Disposition (filename, name),
     * else Content-Type (filename, name), else Content-Location. Null when the part declares no file name;
     * an empty string when it declares an empty one.
     */
    public function filename(): ?string
    {
        if (null !== $this->rawHeader('content-disposition')) {
            $name = $this->param('content-disposition', 'filename') ?? $this->param('content-disposition', 'name');
            if (null !== $name) {
                return $name;
            }
        }
        if (null !== $this->rawHeader('content-type')) {
            $name = $this->param('content-type', 'filename') ?? $this->param('content-type', 'name');
            if (null !== $name) {
                return $name;
            }
        }

        return $this->contentLocation();
    }

    /** Content-Location (RFC 2557) as parsed by the Mail gem: a quoted string or a token, URLs and paths are not file names */
    private function contentLocation(): ?string
    {
        $raw = $this->rawHeader('content-location');
        if (null === $raw || '' === trim($raw)) {
            return null;
        }
        $cfws = '(?:\s|\((?:[^()\\\\]|\\\\.)*\))*';
        if (!preg_match('/\A'.$cfws.'(?:"((?:[^"\\\\]|\\\\.)*)"|([\x21-\x27\x2a-\x2e\x30-\x39\x41-\x5a\x5e-\x7e=]+))'.$cfws.'\z/', $raw, $m)) {
            return null;
        }

        return isset($m[2]) && '' !== $m[2] ? self::decodeHeader($m[2]) : self::decodeHeader(stripcslashes($m[1]));
    }

    /**
     * Mail::Part#attachment?: a part is an attachment when (and only when) it has a file name. Parts without one
     * (inline images referenced by Content-ID, "Content-Disposition: attachment" without filename, unnamed
     * non-text parts) are not attachments; named text parts are, and are left out of the message body.
     */
    public function isAttachment(): bool
    {
        return null !== $this->filename();
    }

    /**
     * Mail::AttachmentsList: the named leaf parts, depth first. A message/rfc822 part is an attachment when it has a
     * file name, and the attachments of the embedded message are extracted as well (when it is not transfer encoded).
     *
     * @return list<MimePart>
     */
    public function attachments(): array
    {
        $out = [];
        foreach ($this->parts as $p) {
            if ('message/rfc822' === $p->mimeType()) {
                if ($p->isAttachment()) {
                    $out[] = $p;
                }
                array_push($out, ...self::parse($p->body)->attachments());
            } elseif ([] === $p->parts) {
                if ($p->isAttachment()) {
                    $out[] = $p;
                }
            } else {
                array_push($out, ...$p->attachments());
            }
        }

        return $out;
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
