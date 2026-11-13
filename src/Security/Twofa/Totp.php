<?php

declare(strict_types=1);

namespace App\Security\Twofa;

/**
 * RFC 6238 TOTP, a port of the ROTP gem (6.x) as used by Redmine::Twofa::Totp:
 * Base32 secrets (ROTP::Base32), 6 digits, SHA1, 30 seconds interval, provisioning URIs.
 */
final class Totp
{
    public const int DEFAULT_INTERVAL = 30;
    public const int DEFAULT_DIGITS = 6;
    private const string CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        public readonly string $secret,
        public readonly ?string $issuer = null,
        public readonly int $interval = self::DEFAULT_INTERVAL,
        public readonly int $digits = self::DEFAULT_DIGITS,
        public readonly string $digest = 'sha1',
    ) {
    }

    /** ROTP::Base32.random: 20 random bytes => 32 Base32 characters */
    public static function randomSecret(int $byteLength = 20): string
    {
        return self::base32Encode(random_bytes($byteLength));
    }

    public static function base32Encode(string $bytes): string
    {
        if ('' === $bytes) {
            return '';
        }
        $out = '';
        $buffer = 0;
        $bitsLeft = 0;
        $len = \strlen($bytes);
        for ($i = 0; $i < $len; ++$i) {
            $buffer = (($buffer << 8) | \ord($bytes[$i])) & 0xFFFF;
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $out .= self::CHARS[($buffer >> ($bitsLeft - 5)) & 31];
                $bitsLeft -= 5;
            }
        }
        if ($bitsLeft > 0) {
            $out .= self::CHARS[($buffer << (5 - $bitsLeft)) & 31];
        }

        return $out;
    }

    /** ROTP::Base32.decode ("=" padding ignored, case-insensitive) */
    public static function base32Decode(string $str): string
    {
        $str = strtoupper(str_replace('=', '', $str));
        $out = '';
        $buffer = 0;
        $bitsLeft = 0;
        $len = \strlen($str);
        for ($i = 0; $i < $len; ++$i) {
            $v = strpos(self::CHARS, $str[$i]);
            if (false === $v) {
                throw new \InvalidArgumentException(\sprintf("Invalid Base32 Character - '%s'", $str[$i]));
            }
            $buffer = (($buffer << 5) | $v) & 0xFFFF;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $out .= \chr(($buffer >> ($bitsLeft - 8)) & 255);
                $bitsLeft -= 8;
            }
        }

        return $out;
    }

    /** HOTP value for a counter (RFC 4226 dynamic truncation) */
    public function generateOtp(int $counter): string
    {
        $hmac = hash_hmac($this->digest, pack('J', $counter), self::base32Decode($this->secret), true);
        $offset = \ord($hmac[\strlen($hmac) - 1]) & 0xF;
        $code = ((\ord($hmac[$offset]) & 0x7F) << 24)
            | ((\ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (\ord($hmac[$offset + 3]) & 0xFF);

        return str_pad((string) ($code % (10 ** $this->digits)), $this->digits, '0', \STR_PAD_LEFT);
    }

    public function timecode(int $timestamp): int
    {
        return intdiv($timestamp, $this->interval);
    }

    public function at(int $timestamp): string
    {
        return $this->generateOtp($this->timecode($timestamp));
    }

    public function now(): string
    {
        return $this->at(time());
    }

    /**
     * ROTP::TOTP#verify: returns the timestamp (timecode * interval) of the last matching
     * time step, or null. Time steps at or before $after are rejected (replay protection).
     */
    public function verify(string $otp, int $driftBehind = 0, int $driftAhead = 0, ?int $after = null, ?int $at = null): ?int
    {
        $now = $at ?? time();
        $start = $this->timecode($now - $driftBehind);
        $end = $this->timecode($now + $driftAhead);
        $result = null;
        for ($t = $start; $t <= $end; ++$t) {
            if (null !== $after && $t <= $this->timecode($after)) {
                continue;
            }
            if ('' !== $otp && hash_equals($this->generateOtp($t), $otp)) {
                $result = $t * $this->interval;
            }
        }

        return $result;
    }

    /** ROTP::OTP::URI#to_s for a TOTP with default parameters */
    public function provisioningUri(string $accountName): string
    {
        $issuer = null === $this->issuer ? null : str_replace(':', '_', trim($this->issuer));
        $label = implode(':', array_map(
            static fn (string $s) => rawurlencode(str_replace(':', '_', $s)),
            array_values(array_filter([$issuer, rtrim($accountName)], static fn ($s) => null !== $s)),
        ));
        $params = ['secret' => $this->secret, 'issuer' => $issuer];
        if ('sha1' !== $this->digest) {
            $params['algorithm'] = strtoupper($this->digest);
        }
        if (self::DEFAULT_DIGITS !== $this->digits) {
            $params['digits'] = (string) $this->digits;
        }
        if (self::DEFAULT_INTERVAL !== $this->interval) {
            $params['period'] = (string) $this->interval;
        }
        $query = [];
        foreach ($params as $k => $v) {
            if (null !== $v) {
                $query[] = $k.'='.rawurlencode($v);
            }
        }

        return 'otpauth://totp/'.$label.'?'.implode('&', $query);
    }
}
