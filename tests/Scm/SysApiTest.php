<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Entity\Repository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** SysController (repository management web service) and the symine:fetch-changesets command */
final class SysApiTest extends ScmWebTestCase
{
    private const string KEY = 'secret-sys-key';

    protected function setUp(): void
    {
        ScmFixtures::requireBinary('git');
        parent::setUp();
        $this->settings()->setMany(['sys_api_enabled' => '1', 'sys_api_key' => self::KEY, 'autofetch_changesets' => '0']);
    }

    public function testAccessDenied(): void
    {
        $this->client->request('GET', '/sys/projects.json?key=wrong');
        self::assertSame(403, $this->httpStatus());
        self::assertSame('Access denied. Repository management WS is disabled or key is invalid.', $this->html());
        $this->client->request('GET', '/sys/projects.json');
        self::assertSame(403, $this->httpStatus());
        $this->client->request('POST', '/sys/fetch_changesets?key=wrong');
        self::assertSame(403, $this->httpStatus());
        $this->settings()->setMany(['sys_api_enabled' => '0']);
        $this->client->request('GET', '/sys/projects.json?key='.self::KEY);
        self::assertSame(403, $this->httpStatus());
    }

    public function testProjectsCreateRepositoryAndFetch(): void
    {
        $this->client->request('GET', '/sys/projects.json?key='.self::KEY);
        self::assertSame(200, $this->httpStatus());
        $projects = json_decode($this->html(), true);
        $ecommerce = array_values(array_filter($projects, static fn ($p) => 'ecommerce' === $p['identifier']))[0];
        self::assertSame(['id', 'name', 'is_public', 'identifier', 'status', 'repository'], array_keys($ecommerce));
        self::assertNull($ecommerce['repository']);
        // only active projects with the repository module, ordered by identifier
        $identifiers = array_column($projects, 'identifier');
        $sorted = $identifiers;
        sort($sorted);
        self::assertSame($sorted, $identifiers);
        self::assertSame(['ecommerce'], $identifiers);
        $this->client->request('GET', '/sys/projects?key='.self::KEY);
        self::assertSame(200, $this->httpStatus());

        [$url] = ScmFixtures::small('git');
        $this->client->request('POST', '/sys/projects/ecommerce/repository?key='.self::KEY, ['vendor' => 'Git', 'repository' => ['url' => $url]]);
        self::assertSame(201, $this->httpStatus(), $this->html());
        $json = json_decode($this->html(), true);
        self::assertSame($url, $json['repository-git']['url']);
        $this->em->clear();
        $repo = $this->em->find(Repository::class, $json['repository-git']['id']);
        self::assertTrue($repo->isDefault);
        // a project has only one repository created by reposman
        $this->client->request('POST', '/sys/projects/ecommerce/repository?key='.self::KEY, ['vendor' => 'Git', 'repository' => ['url' => $url]]);
        self::assertSame(409, $this->httpStatus());
        $this->client->request('POST', '/sys/projects/checkout/repository?key='.self::KEY, ['vendor' => 'Nope', 'repository' => ['url' => $url]]);
        self::assertSame(422, $this->httpStatus());
        $this->client->request('POST', '/sys/projects/nope/repository?key='.self::KEY, ['vendor' => 'Git']);
        self::assertSame(404, $this->httpStatus());

        $this->client->request('GET', '/sys/projects.json?key='.self::KEY);
        $ecommerce = array_values(array_filter(json_decode($this->html(), true), static fn ($p) => 'ecommerce' === $p['identifier']))[0];
        self::assertSame(['id' => $repo->id, 'url' => $url], $ecommerce['repository']);

        // fetch_changesets
        self::assertSame(0, $this->changesetCount($repo));
        $this->client->request('GET', '/sys/fetch_changesets?key='.self::KEY.'&id=nope');
        self::assertSame(404, $this->httpStatus());
        $this->client->request('POST', '/sys/fetch_changesets?key='.self::KEY.'&id=ecommerce');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(3, $this->changesetCount($repo));
        self::assertSame([13], $this->referencedIssues($repo, 'Initial import'));
        $this->client->request('GET', '/sys/fetch_changesets?key='.self::KEY);
        self::assertSame(200, $this->httpStatus());
    }

    public function testFetchChangesetsCommand(): void
    {
        [$url] = ScmFixtures::small('git');
        $repo = $this->createRepository('Git', ['identifier' => 'cmd', 'url' => $url]);
        self::assertSame(0, $this->changesetCount($repo));
        $tester = new CommandTester((new Application(static::$kernel))->find('symine:fetch-changesets'));
        self::assertSame(0, $tester->execute([]));
        self::assertSame(3, $this->changesetCount($repo));
        self::assertSame(0, $tester->execute(['--project' => 'ecommerce']));
        self::assertSame(1, $tester->execute(['--project' => 'nope']));
    }
}
