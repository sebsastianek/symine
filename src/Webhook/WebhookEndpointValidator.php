<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Port of Redmine's WebhookEndpointValidator: only http(s) URLs on "safe" ports whose host resolves to
 * public addresses (no loopback, link-local, 0.0.0.0/::, 224.0.0.0/24, nor addresses of the blocklist)
 * are allowed, to prevent SSRF. The blocklist (Redmine's configuration.yml webhook_blocklist) is read
 * from the SYMINE_WEBHOOK_BLOCKLIST environment variable: comma separated IPs/CIDRs, hosts or *.domains.
 */
final class WebhookEndpointValidator
{
    public const array BAD_PORTS = [1, 7, 9, 11, 13, 15, 17, 19, 20, 21, 22, 23, 25, 37, 42, 43, 53, 69, 77, 79, 87, 95, 101, 102, 103, 104, 109, 110, 111, 113, 115, 117, 119, 123, 135, 137, 139, 143, 161, 179, 389, 427, 465, 512, 513, 514, 515, 526, 530, 531, 532, 540, 548, 554, 556, 563, 587, 601, 636, 989, 990, 993, 995, 1719, 1720, 1723, 2049, 3659, 4045, 4190, 5060, 5061, 6000, 6566, 6665, 6666, 6667, 6668, 6669, 6679, 6697, 10080];

    /** @var (\Closure(string): list<string>)|null DNS resolver (tests) */
    private ?\Closure $resolver = null;

    /** @param list<string>|null $blocklist */
    public function __construct(private ?array $blocklist = null)
    {
    }

    /** @param \Closure(string): list<string> $resolver */
    public function setResolver(\Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function isSafe(string $url): bool
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        $port = $parts['port'] ?? ('https' === strtolower($parts['scheme']) ? 443 : 80);
        if ($port < 1 || $port > 65535 || \in_array($port, self::BAD_PORTS, true)) {
            return false;
        }

        return null !== $this->validIps($parts['host']);
    }

    /** @return list<string> IPs to connect to (empty when the URL is not safe) */
    public function ipsForUrl(string $url): array
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) ? ($this->validIps($host) ?? []) : [];
    }

    /** @return list<string>|null null when the host is not allowed */
    public function validIps(string $host): ?array
    {
        $host = trim($host, '[]');
        if ('' === $host) {
            return null;
        }
        [$blockedIps, $hostRegex] = $this->blocked();
        if (null !== $hostRegex && preg_match($hostRegex, $host)) {
            return null;
        }
        $ips = $this->resolve($host);
        if ([] === $ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                return null;
            }
            foreach ($blockedIps as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return null;
                }
            }
        }

        return $ips;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if (filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if (null !== $this->resolver) {
            return ($this->resolver)($host);
        }
        $ips = [];
        foreach (@dns_get_record($host, \DNS_A | \DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));
        if ([] === $ips) {
            $v4 = gethostbynamel($host);
            $ips = false === $v4 ? [] : $v4;
        }

        return array_values(array_unique($ips));
    }

    public static function isPublic(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if (false === $bin) {
            return false;
        }
        // IPv4-mapped IPv6 -> IPv4 (IPAddr#native)
        if (16 === \strlen($bin) && str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {
            $ip = inet_ntop(substr($bin, 12));
            $bin = substr($bin, 12);
        }
        if (trim($bin, "\0") === '') {
            return false; // 0.0.0.0 / ::
        }
        foreach (['127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/24', '::1/128', 'fe80::/10'] as $cidr) {
            if (self::inCidr((string) $ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if (false === $ipBin || false === $netBin || \strlen($ipBin) !== \strlen($netBin)) {
            return false;
        }
        $bits = null === $bits ? \strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if (0 === $rem) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (\ord($ipBin[$bytes]) & $mask) === (\ord($netBin[$bytes]) & $mask);
    }

    /** @return array{0: list<string>, 1: string|null} */
    private function blocked(): array
    {
        $list = $this->blocklist ?? array_values(array_filter(array_map('trim', explode(',', (string) ($_SERVER['SYMINE_WEBHOOK_BLOCKLIST'] ?? $_ENV['SYMINE_WEBHOOK_BLOCKLIST'] ?? '')))));
        $ips = $hosts = $wildcards = [];
        foreach ($list as $block) {
            $net = explode('/', $block, 2)[0];
            if (false !== @inet_pton($net)) {
                $ips[] = $block;
            } elseif (str_starts_with($block, '*.')) {
                $wildcards[] = preg_quote(substr($block, 2), '/');
            } else {
                $hosts[] = preg_quote($block, '/');
            }
        }
        $parts = [];
        if ($hosts) {
            $parts[] = '(?:'.implode('|', $hosts).')';
        }
        if ($wildcards) {
            $parts[] = '(?:.*\.)?(?:'.implode('|', $wildcards).')';
        }

        return [$ips, $parts ? '/\A(?:'.implode('|', $parts).')\z/i' : null];
    }
}
