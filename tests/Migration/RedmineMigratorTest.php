<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\User;
use App\Entity\WikiContentVersion;
use App\Migration\RedmineComparator;
use App\Migration\RedmineMigrator;
use App\Service\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SQLite → SQLite migrations using the database of the seeded reference Redmine
 * (tests/fixtures/redmine-seeded.db) as source; the target is the test database.
 * Real MySQL/PostgreSQL runs are documented in docs/MIGRATION.md.
 */
final class RedmineMigratorTest extends KernelTestCase
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
        $this->sourcePath = $dir.'/var/test'.$token.'-redmine-src.db';
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

    private function target(): Connection
    {
        return $this->em->getConnection();
    }

    private function assertCountsMatch(array $result): void
    {
        foreach ($this->migrator->compareCounts($this->source) as $table => $c) {
            self::assertSame($c['source'], $c['target'] + ($result['tables'][$table]['skipped'] ?? 0), "row count of $table");
        }
    }

    public function testFullMigrationIsIdentical(): void
    {
        $this->target()->executeStatement("UPDATE issues SET subject = 'garbage'");
        $result = $this->migrator->migrate($this->source, true, 3); // tiny batches: exercises keyset pagination
        $this->assertCountsMatch($result);
        self::assertSame('20260520164915', $result['version']);

        $cmp = self::getContainer()->get(RedmineComparator::class)->compare($this->source, null, 100);
        self::assertSame([], $cmp['differences']);
        self::assertGreaterThan(0, $cmp['tables']['issues']['sampled']);

        // YAML columns are now JSON and readable by the application
        $raw = (string) $this->target()->fetchOne("SELECT value FROM settings WHERE name = 'default_projects_tracker_ids'");
        if ('' !== $raw) {
            self::assertStringStartsWith('[', $raw);
        }
        $perms = (string) $this->target()->fetchOne("SELECT permissions FROM roles WHERE builtin = 0 ORDER BY id");
        self::assertStringStartsWith('[', $perms);
        self::assertSame('common_mark', self::getContainer()->get(Settings::class)->string('text_formatting'));

        // id generators continue after the migrated ids
        $maxJournal = (int) $this->target()->fetchOne('SELECT MAX(id) FROM journals');
        $journal = new Journal();
        $journal->issue = $this->em->find(Issue::class, (int) $this->target()->fetchOne('SELECT MIN(id) FROM issues'));
        $journal->user = $this->em->getRepository(User::class)->findOneBy(['login' => 'admin']);
        $journal->notes = 'after migration';
        $journal->createdOn = new \DateTimeImmutable();
        $this->em->persist($journal);
        $this->em->flush();
        self::assertGreaterThan($maxJournal, $journal->id);
    }

    public function testLegacySchemaAndDirtyData(): void
    {
        $s = $this->source;
        // Redmine < 3.0: users.mail instead of email_addresses
        $s->executeStatement('ALTER TABLE users ADD COLUMN mail varchar(60)');
        $s->executeStatement('UPDATE users SET mail = (SELECT address FROM email_addresses e WHERE e.user_id = users.id AND e.is_default = 1)');
        $mails = (int) $s->fetchOne("SELECT COUNT(*) FROM users WHERE type = 'User' AND mail IS NOT NULL AND mail <> ''");
        $s->executeStatement('DROP TABLE email_addresses');
        // tables of recent versions are missing
        $s->executeStatement('DROP TABLE reactions');
        $s->executeStatement('DROP TABLE webhooks');
        $s->executeStatement('DROP TABLE projects_webhooks');
        // NOT NULL column without default missing in the source, legacy NULLs in a NOT NULL column
        $s->executeStatement('DROP INDEX index_journals_on_created_on');
        $s->executeStatement('ALTER TABLE journals DROP COLUMN created_on');
        $s->executeStatement('ALTER TABLE issues DROP COLUMN done_ratio');
        $s->executeStatement('ALTER TABLE issues ADD COLUMN done_ratio integer');
        // plugin rows and tables
        $s->executeStatement("INSERT INTO users (id, login, firstname, lastname, type, status, admin, hashed_password, mail_notification, must_change_passwd) VALUES (900, 'bot', '', 'Bot', 'PluginPrincipal', 1, 0, '', '', 0)");
        $s->executeStatement("INSERT INTO enumerations (id, name, position, is_default, type, active) VALUES (900, 'Plugin', 1, 0, 'PluginEnumeration', 1)");
        $s->executeStatement('CREATE TABLE plugin_things (id integer primary key, name varchar(10))');
        // YAML saved from controller params (old Redmine), latin1 bytes, dangling references, gzip wiki data
        $s->executeStatement('UPDATE queries SET filters = ? WHERE id = 1', ["--- !ruby/object:ActionController::Parameters\nparameters: !ruby/hash:ActiveSupport::HashWithIndifferentAccess\n  tracker_id: !ruby/hash:ActiveSupport::HashWithIndifferentAccess\n    operator: \"=\"\n    values:\n    - '1'\npermitted: false\n"]);
        $first = (int) $s->fetchOne('SELECT MIN(id) FROM issues');
        $second = (int) $s->fetchOne('SELECT MIN(id) FROM issues WHERE id > ?', [$first]);
        $s->executeStatement('UPDATE issues SET description = ? WHERE id = ?', ["H\xE9llo", $first], [ParameterType::BINARY, ParameterType::INTEGER]);
        $s->executeStatement('UPDATE issues SET category_id = 9999 WHERE id = ?', [$second]);
        $s->executeStatement('UPDATE journals SET user_id = 9999 WHERE id = (SELECT MIN(id) FROM journals)');
        $vid = (int) $s->fetchOne('SELECT MAX(id) FROM wiki_content_versions');
        $s->executeStatement("UPDATE wiki_content_versions SET compression = 'gzip', data = ? WHERE id = ?", [gzcompress('Compressed wiki text äöü'), $vid], [ParameterType::LARGE_OBJECT, ParameterType::INTEGER]);

        $dry = $this->migrator->dryRun($this->source);
        self::assertSame([], $dry['errors']);
        $dryWarnings = implode("\n", $dry['warnings']);
        self::assertStringContainsString('email_addresses will be created from users.mail', $dryWarnings);
        self::assertStringContainsString('unsupported type "PluginPrincipal"', $dryWarnings);
        self::assertStringContainsString('plugin_things', $dryWarnings);
        self::assertStringContainsString('issues.category_id', $dryWarnings);

        $result = $this->migrator->migrate($this->source);
        $this->assertCountsMatch(['tables' => $result['tables']]);
        $warnings = implode("\n", $result['warnings']);

        self::assertSame($mails, (int) $this->target()->fetchOne('SELECT COUNT(*) FROM email_addresses'));
        self::assertSame('jsmith@example.net', (string) $this->target()->fetchOne("SELECT e.address FROM email_addresses e JOIN users u ON u.id = e.user_id WHERE u.login = 'jsmith' AND e.is_default = 1"));
        self::assertSame(1, $result['tables']['users']['skipped']);
        self::assertSame(1, $result['tables']['enumerations']['skipped']);
        self::assertStringContainsString('Table "webhooks" does not exist in the source', $warnings);
        self::assertStringContainsString('journals.created_on is missing in the source', $warnings);
        self::assertSame(0, (int) $this->target()->fetchOne('SELECT COUNT(*) FROM journals WHERE created_on IS NULL'));
        self::assertSame(0, (int) $this->target()->fetchOne('SELECT COUNT(*) FROM issues WHERE done_ratio IS NULL'));
        self::assertStringContainsString('NOT NULL column issues.done_ratio', $warnings);
        self::assertSame('{"tracker_id":{"operator":"=","values":["1"]}}', $this->target()->fetchOne('SELECT filters FROM queries WHERE id = 1'));
        self::assertSame('Héllo', $this->target()->fetchOne('SELECT description FROM issues WHERE id = ?', [$first]));
        self::assertStringContainsString('Windows-1252', $warnings);
        self::assertNull($this->target()->fetchOne('SELECT category_id FROM issues WHERE id = ?', [$second]));
        $anonymous = (int) $this->target()->fetchOne("SELECT id FROM users WHERE type = 'AnonymousUser'");
        self::assertSame($anonymous, (int) $this->target()->fetchOne('SELECT user_id FROM journals ORDER BY id'));

        $this->em->clear();
        $version = $this->em->find(WikiContentVersion::class, $vid);
        self::assertSame('Compressed wiki text äöü', $version->getText());
    }

    public function testOnlyRemigratesSelectedTables(): void
    {
        $this->migrator->migrate($this->source);
        $this->target()->executeStatement("UPDATE issues SET subject = 'changed in Symine'");
        $this->target()->executeStatement("UPDATE news SET title = 'kept'");
        self::assertSame(['issues', 'journals'], RedmineMigrator::parseOnly('journals, issues'));

        $result = $this->migrator->migrate($this->source, true, 500, ['issues', 'journals']);
        self::assertSame(['issues', 'journals'], array_keys($result['tables']));
        self::assertSame(0, (int) $this->target()->fetchOne("SELECT COUNT(*) FROM issues WHERE subject = 'changed in Symine'"));
        self::assertSame(0, (int) $this->target()->fetchOne("SELECT COUNT(*) FROM news WHERE title <> 'kept'"));

        $this->expectException(\InvalidArgumentException::class);
        RedmineMigrator::parseOnly('issues,plugin_table');
    }

    public function testDryRunWritesNothingAndReportsBadYaml(): void
    {
        $this->source->executeStatement('UPDATE queries SET column_names = ? WHERE id = 1', ["---\nkey: [a, b\n"]);
        $before = (string) $this->target()->fetchOne("SELECT COUNT(*) || '-' || MAX(subject) FROM issues");
        $first = (int) $this->target()->fetchOne('SELECT MIN(id) FROM issues');
        $this->target()->executeStatement("UPDATE issues SET subject = 'untouched' WHERE id = ?", [$first]);

        $result = $this->migrator->dryRun($this->source, 2);
        self::assertSame(1, $result['tables']['queries']['yaml_errors']);
        self::assertStringContainsString('queries.column_names of #1', $result['errors'][0]);
        self::assertGreaterThan(0, $result['tables']['issues']['source']);
        self::assertSame('untouched', $this->target()->fetchOne('SELECT subject FROM issues WHERE id = ?', [$first]));
        self::assertNotSame('', $before);
    }

    public function testCompareDetectsDifferences(): void
    {
        $this->migrator->migrate($this->source);
        $first = (int) $this->target()->fetchOne('SELECT MIN(id) FROM issues');
        $this->target()->executeStatement("UPDATE issues SET subject = 'tampered' WHERE id = ?", [$first]);
        $this->target()->executeStatement("UPDATE settings SET value = '[\"x\"]' WHERE name = (SELECT name FROM settings WHERE value LIKE '[%' ORDER BY id LIMIT 1)");
        $this->target()->executeStatement("DELETE FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ?", [$first]);

        $result = self::getContainer()->get(RedmineComparator::class)->compare($this->source, null, 100);
        $diff = implode("\n", $result['differences']);
        self::assertStringContainsString("issues #$first: subject differs", $diff);
        self::assertStringContainsString("issue #$first: custom_values differ", $diff);
        self::assertMatchesRegularExpression('/settings #\d+: value differs/', $diff);
        self::assertStringContainsString('custom_values:', $diff); // row count mismatch
    }

    public function testCommands(): void
    {
        $app = new Application(self::$kernel);
        $url = 'sqlite:///'.$this->sourcePath;

        $dry = new CommandTester($app->find('symine:migrate-from-redmine'));
        self::assertSame(0, $dry->execute(['--source' => $url, '--dry-run' => true]));
        self::assertStringContainsString('nothing was written', $dry->getDisplay());

        $migrate = new CommandTester($app->find('symine:migrate-from-redmine'));
        self::assertSame(0, $migrate->execute(['--source' => $url, '--force' => true, '--batch' => '7']));
        self::assertStringContainsString('all row counts match', $migrate->getDisplay());

        $compare = new CommandTester($app->find('symine:redmine:compare'));
        self::assertSame(0, $compare->execute(['--source' => $url, '--sample' => '20']));
        self::assertStringContainsString('No difference found', $compare->getDisplay());

        $this->em->getConnection()->executeStatement("UPDATE users SET lastname = 'X' WHERE login = 'jsmith'");
        self::assertSame(1, $compare->execute(['--source' => $url, '--tables' => 'users']));
        self::assertStringContainsString('lastname differs', $compare->getDisplay());

        $only = new CommandTester($app->find('symine:migrate-from-redmine'));
        self::assertSame(1, $only->execute(['--source' => $url, '--force' => true, '--only' => 'nope']));
    }

    public function testRedmineCipheredPasswordsAreDecrypted(): void
    {
        // produced by Redmine::Ciphering.encrypt_text with database_cipher_key "my-cipher-key" (Ruby OpenSSL)
        $ciphered = 'aes-256-cbc:RUrZfNKjuXmOClMpTsNGcw==--OeJ6oykg51iboH/Mg1anqA==';
        $this->source->executeStatement("INSERT INTO auth_sources (id, type, name, host, port, account, account_password, base_dn, attr_login, onthefly_register, tls, verify_peer) VALUES (1, 'AuthSourceLdap', 'Corp LDAP', 'ldap.example.net', 389, 'cn=admin', ?, 'dc=example,dc=net', 'uid', 0, 0, 1)", [$ciphered]);

        $result = $this->migrator->migrate($this->source, true, 500, ['auth_sources']);
        self::assertSame($ciphered, $this->target()->fetchOne('SELECT account_password FROM auth_sources WHERE id = 1'));
        self::assertStringContainsString('re-run with --cipher-key', implode("\n", $result['warnings']));

        $this->migrator->setCipherKey('my-cipher-key');
        $this->migrator->migrate($this->source, true, 500, ['auth_sources']);
        self::assertSame('Sécret ldap', $this->target()->fetchOne('SELECT account_password FROM auth_sources WHERE id = 1'));
        $cmp = self::getContainer()->get(RedmineComparator::class)->compare($this->source, null, 10, ['auth_sources']);
        self::assertSame([], $cmp['differences']);
        self::assertStringContainsString('decrypted', implode("\n", $cmp['notices']));
        self::assertNull(RedmineMigrator::decryptRedmineText($ciphered, 'wrong key'));
    }

    public function testLargeTableIsStreamedWithBoundedMemory(): void
    {
        $this->migrator->migrate($this->source); // warm-up (schema metadata, caches)
        $this->source->beginTransaction();
        $stmt = $this->source->prepare("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Issue', 1, 1, ?)");
        for ($i = 0; $i < 60000; ++$i) {
            $stmt->bindValue(1, str_repeat('v', 200).$i);
            $stmt->executeStatement();
        }
        $this->source->commit();

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $result = $this->migrator->migrate($this->source, true, 1000, ['custom_values']);
        $growth = memory_get_peak_usage() - $before;
        self::assertSame($result['tables']['custom_values']['source'], $result['tables']['custom_values']['copied']);
        self::assertGreaterThan(60000, $result['tables']['custom_values']['copied']);
        // 60k rows x ~200 bytes = 12 MB of raw data alone; streaming keeps the footprint constant (~4 MB)
        self::assertLessThan(8 * 1024 * 1024, $growth, 'migration memory must not grow with the table size');
    }
}
