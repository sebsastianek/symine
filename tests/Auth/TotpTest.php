<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Migration\RedmineMigrator;
use App\Security\Twofa\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TOTP (RFC 6238) and compatibility with the ROTP gem used by Redmine::Twofa::Totp.
 * ROTP values below were computed by the reference Redmine (bin/rails runner).
 */
final class TotpTest extends TestCase
{
    /** RFC 6238 Appendix B: [time, sha1, sha256, sha512] (8 digits) */
    public static function rfc6238Vectors(): array
    {
        return [
            [59, '94287082', '46119246', '90693936'],
            [1111111109, '07081804', '68084774', '25091201'],
            [1111111111, '14050471', '67062674', '99943326'],
            [1234567890, '89005924', '91819424', '93441116'],
            [2000000000, '69279037', '90698825', '38618901'],
            [20000000000, '65353130', '77737706', '47863826'],
        ];
    }

    #[DataProvider('rfc6238Vectors')]
    public function testRfc6238Vectors(int $time, string $sha1, string $sha256, string $sha512): void
    {
        $seeds = [
            'sha1' => '12345678901234567890',
            'sha256' => '12345678901234567890123456789012',
            'sha512' => '1234567890123456789012345678901234567890123456789012345678901234',
        ];
        foreach (['sha1' => $sha1, 'sha256' => $sha256, 'sha512' => $sha512] as $algo => $expected) {
            $totp = new Totp(Totp::base32Encode($seeds[$algo]), null, 30, 8, $algo);
            self::assertSame($expected, $totp->at($time), "$algo at $time");
        }
    }

    public function testBase32MatchesRotp(): void
    {
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32Encode('12345678901234567890'));
        self::assertSame('12345678901234567890', Totp::base32Decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'));
        self::assertSame('12345678901234567890', Totp::base32Decode('gezdgnbvgy3tqojqgezdgnbvgy3tqojq===='));
        foreach ([1, 5, 7, 20, 33] as $len) {
            $bytes = random_bytes($len);
            self::assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        }
        $key = Totp::randomSecret();
        self::assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $key);
        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('ABC1');
    }

    public function testCodesOfAKeyCreatedByRedmine(): void
    {
        // key of a user paired in the reference Redmine (ROTP::TOTP.new(key).at(t))
        $totp = new Totp('OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT', 'localhost:3000');
        $expected = [0 => '268075', 59 => '348758', 1111111109 => '183667', 1234567890 => '023647', 2000000000 => '610443', 1794065610 => '739288'];
        foreach ($expected as $t => $code) {
            self::assertSame($code, $totp->at($t), "at $t");
        }
        self::assertSame('otpauth://totp/localhost_3000:rhill?secret=OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT&issuer=localhost_3000', $totp->provisioningUri('rhill'));
        self::assertSame('otpauth://totp/jsmith?secret=OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT', (new Totp('OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT'))->provisioningUri('jsmith'));
    }

    public function testVerifyDriftAndReplayProtection(): void
    {
        $totp = new Totp('OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT');
        $now = 1794065615; // timecode 59802187 starts at 1794065610
        // current step
        self::assertSame(1794065610, $totp->verify($totp->at($now), 30, 0, null, $now));
        // previous step accepted (drift_behind 30s), the one before is not
        self::assertSame(1794065580, $totp->verify($totp->at($now - 30), 30, 0, null, $now));
        self::assertNull($totp->verify($totp->at($now - 60), 30, 0, null, $now));
        // next step is not accepted (no drift ahead)
        self::assertNull($totp->verify($totp->at($now + 30), 30, 0, null, $now));
        // replay protection: steps at or before "after" are rejected (ROTP returns the same as Redmine: 1794065610)
        self::assertSame(1794065610, $totp->verify($totp->at($now), 30, 0, 1794065580, $now));
        self::assertNull($totp->verify($totp->at($now), 30, 0, 1794065610, $now));
        self::assertNull($totp->verify($totp->at($now - 30), 30, 0, 1794065580, $now));
        self::assertNull($totp->verify('', 30, 0, null, $now));
        self::assertNull($totp->verify('12345', 30, 0, null, $now));
    }

    public function testRedmineCipheredKeyIsDecrypted(): void
    {
        // Redmine::Ciphering.encrypt_text with database_cipher_key "symine-test-cipher-key" (computed by Ruby)
        $enc = 'aes-256-cbc:avZFg50gD9d7HBZiSGhPdkcYcwt7irGPd32J0hHA/kXPhbQUOx6z5J61JWp2r5Yv--wkaL8vt+LfTuRZKPxCq85w==';
        self::assertSame('OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT', RedmineMigrator::decryptRedmineText($enc, 'symine-test-cipher-key'));
    }
}
