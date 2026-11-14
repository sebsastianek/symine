<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Plugin\PluginSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * "The tables of this plugin exist" is remembered across requests (PluginSchema): for the database it was
 * asked about and for no other, and never longer than the tables themselves.
 *
 * Every `new PluginSchema()` below stands for a request (or a server): what survives from one to the next is
 * the cache pool they share, nothing else.
 */
final class PluginSchemaTest extends TestCase
{
    private const array TABLES = ['hello_greetings'];

    /** @var list<string> */
    private array $files = [];
    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function database(bool $withTables): Connection
    {
        $this->files[] = $file = (string) tempnam(sys_get_temp_dir(), 'symine-plugin-schema');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        if ($withTables) {
            $connection->executeStatement('CREATE TABLE hello_greetings (id INTEGER PRIMARY KEY)');
        }

        return $connection;
    }

    private function request(Connection $connection): PluginSchema
    {
        return new PluginSchema($connection, $this->pool);
    }

    public function testYesIsRememberedWithoutLookingAtTheSchemaAgain(): void
    {
        $db = $this->database(true);
        self::assertTrue($this->request($db)->hasTables('symine_hello', self::TABLES));

        // had the next request looked at the schema, it would have seen that the table is gone
        $db->executeStatement('DROP TABLE hello_greetings');
        self::assertTrue($this->request($db)->hasTables('symine_hello', self::TABLES));
    }

    public function testAnAnswerBelongsToItsDatabase(): void
    {
        $migrated = $this->database(true);
        $fresh = $this->database(false);

        // two servers of the same checkout share the cache directory, not the database
        self::assertTrue($this->request($migrated)->hasTables('symine_hello', self::TABLES));
        self::assertFalse($this->request($fresh)->hasTables('symine_hello', self::TABLES), 'the answer of another database is not ours');
        self::assertTrue($this->request($migrated)->hasTables('symine_hello', self::TABLES));

        // and the other way round: what is remembered for the second database says nothing about the first
        $fresh->executeStatement('CREATE TABLE hello_greetings (id INTEGER PRIMARY KEY)');
        self::assertTrue($this->request($fresh)->hasTables('symine_hello', self::TABLES));
        $migrated->executeStatement('DROP TABLE hello_greetings');
        $this->request($migrated)->forget('symine_hello');
        self::assertFalse($this->request($migrated)->hasTables('symine_hello', self::TABLES));
        self::assertTrue($this->request($fresh)->hasTables('symine_hello', self::TABLES), 'forgetting is per database too');
    }

    public function testAnAnswerBelongsToItsPlugin(): void
    {
        $db = $this->database(true);
        self::assertTrue($this->request($db)->hasTables('symine_hello', self::TABLES));
        self::assertFalse($this->request($db)->hasTables('symine_kanban', ['kanban_boards']));
        // a plugin whose list of tables grew is asked again
        self::assertFalse($this->request($db)->hasTables('symine_hello', ['hello_greetings', 'hello_replies']));
    }

    public function testNoIsNeverRemembered(): void
    {
        $db = $this->database(false);
        $request = $this->request($db);
        self::assertFalse($request->hasTables('symine_hello', self::TABLES));
        $db->executeStatement('CREATE TABLE hello_greetings (id INTEGER PRIMARY KEY)');
        self::assertFalse($request->hasTables('symine_hello', self::TABLES), 'one answer per request');
        self::assertTrue($this->request($db)->hasTables('symine_hello', self::TABLES), 'the next request sees the migrated plugin');

        // a worker that serves several requests asks again for each of them
        $db->executeStatement('DROP TABLE hello_greetings');
        $worker = $this->request($db);
        $worker->forget('symine_hello');
        self::assertFalse($worker->hasTables('symine_hello', self::TABLES));
        $db->executeStatement('CREATE TABLE hello_greetings (id INTEGER PRIMARY KEY)');
        $worker->reset();
        self::assertTrue($worker->hasTables('symine_hello', self::TABLES));
    }

    public function testAMissingTableIsNoticedAtOnce(): void
    {
        $db = $this->database(true);
        $request = $this->request($db);
        self::assertTrue($request->hasTables('symine_hello', self::TABLES));

        // the tables went away behind our back: the code that meets the error reports it
        $db->executeStatement('DROP TABLE hello_greetings');
        $request->missing('symine_hello');
        self::assertFalse($request->hasTables('symine_hello', self::TABLES), 'not installed for the rest of the request');
        self::assertFalse($this->request($db)->hasTables('symine_hello', self::TABLES), 'nor for the following ones');
    }

    public function testADatabaseInMemoryHasNoIdentityToRememberItBy(): void
    {
        $first = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $first->executeStatement('CREATE TABLE hello_greetings (id INTEGER PRIMARY KEY)');
        self::assertTrue($this->request($first)->hasTables('symine_hello', self::TABLES));

        $second = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::assertFalse($this->request($second)->hasTables('symine_hello', self::TABLES));
    }
}
