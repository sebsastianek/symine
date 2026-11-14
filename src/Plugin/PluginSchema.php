<?php

declare(strict_types=1);

namespace App\Plugin;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the tables of a plugin exist, i.e. whether its migrations have been run
 * (`bin/console symine:plugins:migrate <id>`). A plugin asks this before it shows up in menus, hooks, the
 * activity or the search, so that installing it cannot break a page; it is therefore asked on every request.
 *
 * Looking at the schema costs two queries, so "yes" is remembered for a few minutes in the application cache.
 *
 * - The answer belongs to a database: it is filed under the identity of the connection (driver, host, port,
 *   database or file, user) and the plugin. Servers that share the cache directory but not the database (a
 *   development server and test servers of the same checkout) never see each other's answer.
 * - "No" is never remembered beyond the request: the plugin must appear as soon as it is migrated.
 * - PluginMigrator forgets the answer whenever it migrates the plugin, up or down.
 * - The tables can still go away behind our back (another server rolled the plugin back, the database was
 *   replaced by a copy without them). Code that then meets a missing table reports it with missing(): the
 *   remembered answer is dropped at once and the plugin counts as not installed. PluginSchemaHealer does that
 *   for any "table not found" error that reaches the kernel; plugins whose code runs inside core pages
 *   (hooks, activity and search providers) should catch the error themselves, see HelloSchema::whenInstalled().
 */
final class PluginSchema implements ResetInterface
{
    private const int TTL = 300;

    /** Parameters that tell one database from another (never the password) */
    private const array IDENTITY = ['driver', 'driverClass', 'url', 'host', 'port', 'unix_socket', 'dbname', 'path', 'servicename', 'user', 'primary'];

    /** @var array<string, bool> answers given during this request */
    private array $known = [];

    private ?string $database = null;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'cache.plugin_schema')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /** @param list<string> $tables */
    public function hasTables(string $pluginId, array $tables): bool
    {
        if (isset($this->known[$pluginId])) {
            return $this->known[$pluginId];
        }
        $key = $this->key($pluginId);
        $fingerprint = implode(',', $tables);
        $item = null === $key ? null : $this->cache->getItem($key);
        if (null !== $item && $item->isHit() && $item->get() === $fingerprint) {
            return $this->known[$pluginId] = true;
        }
        $exist = $this->connection->createSchemaManager()->tablesExist($tables);
        if ($exist && null !== $item) {
            $this->cache->save($item->set($fingerprint)->expiresAfter(self::TTL));
        }

        return $this->known[$pluginId] = $exist;
    }

    /** After the migrations of the plugin were run or rolled back: the next question looks at the schema */
    public function forget(string $pluginId): void
    {
        unset($this->known[$pluginId]);
        if (null !== ($key = $this->key($pluginId))) {
            $this->cache->deleteItem($key);
        }
    }

    /** A table of the plugin turned out not to exist: nothing is remembered, and it is not installed for this request */
    public function missing(string $pluginId): void
    {
        $this->forget($pluginId);
        $this->known[$pluginId] = false;
    }

    public function reset(): void
    {
        $this->known = [];
    }

    /** Cache key of the answer for this plugin in this database; null when the database has no identity (in memory) */
    private function key(string $pluginId): ?string
    {
        if (null === $this->database) {
            $params = $this->connection->getParams();
            if (true === ($params['memory'] ?? false) || ':memory:' === ($params['path'] ?? null)) {
                return null;
            }
            $identity = array_intersect_key($params, array_flip(self::IDENTITY));
            if (isset($identity['primary']) && \is_array($identity['primary'])) {
                unset($identity['primary']['password']);
            }
            if (isset($identity['url']) && \is_string($identity['url'])) {
                // user:password@ of a URL that was not broken down into parameters
                $identity['url'] = preg_replace('#(://[^:/@]*):[^@/]*@#', '$1@', $identity['url']);
            }
            ksort($identity);
            $this->database = hash('xxh128', serialize($identity));
        }

        return 'tables.'.$this->database.'.'.hash('xxh128', $pluginId);
    }
}
