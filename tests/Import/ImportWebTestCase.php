<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Entity\Import;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Functional tests of the CSV import wizard (ImportsController) on a fresh copy of the seeded database.
 */
abstract class ImportWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2);
        copy($dir.'/tests/fixtures/symine-seeded.db', $dir.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    protected function login(string $login): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        self::assertNotNull($user);
        // User::__serialize() does not keep the salt: a legacy salted SHA1 hash would make the session
        // user look "changed" on refresh (logout). The salt is irrelevant for loginUser().
        if (null !== $user->salt) {
            $this->em->getConnection()->executeStatement('UPDATE users SET salt = NULL WHERE id = ?', [$user->id]);
            $user->salt = null;
        }
        $this->client->loginUser($user, 'main');

        return $user;
    }

    protected function csrf(string $url = '/'): string
    {
        $crawler = $this->client->request('GET', $url);

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    protected function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function location(): string
    {
        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /** Uploads the CSV (POST /imports) and returns the import filename (URL id) */
    protected function upload(string $type, string $csv, ?string $projectId = null): string
    {
        $token = $this->csrf();
        $path = sys_get_temp_dir().'/symine-import-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $csv);
        $this->tmpFiles[] = $path;
        $this->client->request('POST', '/imports', ['_token' => $token, 'type' => $type, 'project_id' => (string) $projectId], [
            'file' => new UploadedFile($path, 'import.csv', 'text/csv', null, true),
        ]);
        self::assertSame(302, $this->code(), (string) $this->client->getResponse()->getContent());
        self::assertMatchesRegularExpression('#/imports/([0-9a-f]{32})/settings$#', $this->location());
        preg_match('#/imports/([0-9a-f]{32})/settings$#', $this->location(), $m);

        return $m[1];
    }

    /** @param array<string, string> $settings */
    protected function postSettings(string $id, array $settings): void
    {
        $token = $this->csrf();
        $this->client->request('POST', "/imports/$id/settings", ['_token' => $token, 'import_settings' => $settings + [
            'separator' => ',', 'wrapper' => '"', 'encoding' => 'UTF-8', 'date_format' => '%Y-%m-%d', 'notifications' => '0',
        ]]);
    }

    /** @param array<string, string> $mapping */
    protected function postMapping(string $id, array $mapping): void
    {
        $token = $this->csrf();
        $this->client->request('POST', "/imports/$id/mapping", ['_token' => $token, 'import_settings' => ['mapping' => $mapping]]);
        self::assertSame(302, $this->code());
        self::assertStringEndsWith("/imports/$id/run", $this->location());
    }

    /** Runs the import in chunks (non-JS flow) until finished; returns the result page HTML */
    protected function runImport(string $id): string
    {
        $this->client->request('GET', "/imports/$id/run");
        self::assertSame(200, $this->code());
        for ($i = 0; $i < 50; ++$i) {
            $token = $this->csrf();
            $this->client->request('POST', "/imports/$id/run", ['_token' => $token]);
            self::assertSame(302, $this->code(), (string) $this->client->getResponse()->getContent());
            if (str_ends_with($this->location(), "/imports/$id")) {
                break;
            }
        }
        self::assertStringEndsWith("/imports/$id", $this->location());
        $this->client->request('GET', "/imports/$id");
        self::assertSame(200, $this->code());

        return (string) $this->client->getResponse()->getContent();
    }

    protected function import(string $id): Import
    {
        $this->em->clear();
        $import = $this->em->getRepository(Import::class)->findOneBy(['filename' => $id]);
        self::assertNotNull($import);

        return $import;
    }

    /** @return list<array{position: int, obj_id: int|null, message: string|null}> */
    protected function items(string $id): array
    {
        return array_map(static fn (array $r) => ['position' => (int) $r['position'], 'obj_id' => null === $r['obj_id'] ? null : (int) $r['obj_id'], 'message' => $r['message']],
            $this->em->getConnection()->fetchAllAssociative('SELECT ii.position, ii.obj_id, ii.message FROM import_items ii JOIN imports i ON i.id = ii.import_id WHERE i.filename = ? ORDER BY ii.position', [$id]));
    }
}
