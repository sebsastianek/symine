<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use App\Migration\RedmineComparator;
use App\Migration\RedmineMigrator;
use App\OAuth\OAuthServer;
use App\Security\Twofa\Totp;
use App\Security\Twofa\TwofaManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Migration of the 2FA columns and of the Doorkeeper tables: data created by the reference Redmine
 * (TOTP key, backup codes, OAuth application with a BCrypt secret, hashed tokens) keeps working in Symine.
 */
final class AuthMigrationTest extends KernelTestCase
{
    private string $sourcePath;
    private Connection $source;
    private EntityManagerInterface $em;
    private RedmineMigrator $migrator;

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2);
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '';
        copy($dir.'/tests/fixtures/symine-seeded.db', $dir.'/var/test'.$token.'.db');
        $this->sourcePath = $dir.'/var/test'.$token.'-auth-src.db';
        copy($dir.'/tests/fixtures/redmine-seeded.db', $this->sourcePath);
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->migrator = self::getContainer()->get(RedmineMigrator::class);
        $this->source = RedmineMigrator::connect('sqlite:///'.$this->sourcePath);
    }

    protected function tearDown(): void
    {
        $this->source->close();
        @unlink($this->sourcePath);
        parent::tearDown();
    }

    public function testTwofaAndOauthDataAreMigrated(): void
    {
        $s = $this->source;
        // values produced by the reference Redmine (bin/rails runner, see TotpTest / OAuthTest)
        $s->executeStatement("UPDATE users SET twofa_scheme = 'totp', twofa_totp_key = 'OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT', twofa_totp_last_used_at = 1794065610 WHERE login = 'rhill'");
        $rhill = (int) $s->fetchOne("SELECT id FROM users WHERE login = 'rhill'");
        $s->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (?, 'twofa_backup_code', 'a299c104e46f', '2026-11-07 15:30:10.123456', '2026-11-07 15:30:10.123456')", [$rhill]);
        $s->executeStatement("INSERT INTO oauth_applications (id, name, uid, secret, redirect_uri, scopes, confidential, created_at, updated_at) VALUES (1, 'Ref App', 'fI83J560tXfH3uw4Ba-Zzh0HxCAaUS6eQzAdjIfr3B8', '\$2a\$12\$B.fRmonmyl5jlmXSVTs4Ru1dGXZn3l6Rhm6EZh6z/aHSYjQ8vsrLe', 'http://localhost:8888/cb', 'view_project search_project view_members view_issues add_issues', 1, '2026-11-07 15:22:59.517634', '2026-11-07 15:22:59.517634')");
        $s->executeStatement("INSERT INTO oauth_access_grants (id, resource_owner_id, application_id, token, expires_in, redirect_uri, created_at, revoked_at, scopes, code_challenge, code_challenge_method) VALUES (1, 5, 1, '2a13630a8da8f159aab4f92ba04429d7b64e5ffafb120a0569b64328445f0734', 600, 'http://localhost:8888/cb', '2026-11-07 15:22:59.6', NULL, 'view_project view_issues', NULL, NULL)");
        $s->executeStatement("INSERT INTO oauth_access_tokens (id, resource_owner_id, application_id, token, refresh_token, expires_in, revoked_at, created_at, scopes, previous_refresh_token) VALUES (1, 5, 1, '694e532bc19dfe0213ec8f6b2803c2bef77c1f0d86e4df5e3ef1f35e5da2676a', 'ea55045148587cbd11adabf9a795d74836ae1b5377284cb2b01b4c5bf3d07abe', 7200000, NULL, '2026-11-07 15:22:59.7', 'view_project search_project view_members view_issues', '')");

        $result = $this->migrator->migrate($this->source);
        foreach ($result['warnings'] as $w) {
            self::assertStringNotContainsString('OAuth', $w);
        }
        self::assertSame(1, $result['tables']['oauth_applications']['copied']);
        self::assertSame(1, $result['tables']['oauth_access_grants']['copied']);
        self::assertSame(1, $result['tables']['oauth_access_tokens']['copied']);
        $cmp = self::getContainer()->get(RedmineComparator::class)->compare($this->source, null, 100);
        self::assertSame([], $cmp['differences']);
        self::assertSame(1, $cmp['tables']['oauth_access_tokens']['target']);

        $conn = $this->em->getConnection();
        self::assertSame('2026-11-07 15:22:59', $conn->fetchOne('SELECT created_at FROM oauth_applications WHERE id = 1'));

        // OAuth: the application secret (BCrypt) and the hashed token still authenticate
        $oauth = self::getContainer()->get(OAuthServer::class);
        $app = $oauth->applicationByUidAndSecret('fI83J560tXfH3uw4Ba-Zzh0HxCAaUS6eQzAdjIfr3B8', 'VPazHTL2SKqEVgPE_nN0kFWa5wcyNFLgZkM-AkR4u5o');
        self::assertNotNull($app);
        self::assertNull($oauth->applicationByUidAndSecret('fI83J560tXfH3uw4Ba-Zzh0HxCAaUS6eQzAdjIfr3B8', 'wrong'));
        $token = $oauth->authenticate('mPhqujqLTBeCspWzPYzKHk8j_EHAZTK6XIV2vH0WEZ0');
        self::assertNotNull($token);
        self::assertSame(5, $token->resourceOwnerId);
        self::assertSame(['view_project', 'search_project', 'view_members', 'view_issues'], $token->getScopes());
        self::assertSame(7200000, $token->expiresIn);

        // 2FA: codes of the authenticator app paired in Redmine, and its backup codes
        $twofa = self::getContainer()->get(TwofaManager::class);
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => 'rhill']);
        self::assertTrue(TwofaManager::isActive($user));
        self::assertTrue($twofa->verifyOtp($user, (new Totp('OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT'))->now()));
        self::assertTrue($twofa->verifyBackupCode($user, 'A299 C104 E46F'));
        self::assertFalse($twofa->verifyBackupCode($user, 'a299c104e46f'));
    }

    public function testEncryptedTotpKeyIsDecryptedWithTheCipherKey(): void
    {
        $enc = 'aes-256-cbc:avZFg50gD9d7HBZiSGhPdkcYcwt7irGPd32J0hHA/kXPhbQUOx6z5J61JWp2r5Yv--wkaL8vt+LfTuRZKPxCq85w==';
        $this->source->executeStatement("UPDATE users SET twofa_scheme = 'totp', twofa_totp_key = ? WHERE login = 'rhill'", [$enc]);
        $this->migrator->setCipherKey('symine-test-cipher-key');
        $this->migrator->migrate($this->source, true, 500, ['users']);
        self::assertSame('OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT', $this->em->getConnection()->fetchOne("SELECT twofa_totp_key FROM users WHERE login = 'rhill'"));
    }
}
