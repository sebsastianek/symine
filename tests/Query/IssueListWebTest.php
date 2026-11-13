<?php

declare(strict_types=1);

namespace App\Tests\Query;

use App\Entity\IssueQuery as IssueQueryRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Issue list pages, exports, API, saved queries CRUD and calendar (functional). */
final class IssueListWebTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2);
        copy($dir.'/tests/fixtures/symine-seeded.db', $dir.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function login(string $login): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        self::assertNotNull($user);
        $this->client->loginUser($user, 'main');
    }

    private function get(string $url): string
    {
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }

    private function token(): string
    {
        return (string) $this->client->request('GET', '/issues')->filter('meta[name="csrf-token"]')->attr('content');
    }

    public function testPagesRenderForAdminAndJsmith(): void
    {
        $urls = [
            '/issues', '/projects/ecommerce/issues', '/issues?set_filter=1&group_by=status&t[]=estimated_hours&t[]=spent_hours&c[]=tracker&c[]=subject&c[]=spent_hours&c[]=total_spent_hours&c[]=relations&c[]=attachments&c[]=watcher_users&c[]=cf_1&c[]=cf_2&c[]=parent&c[]=parent.subject&c[]=last_updated_by&c[]=description&c[]=last_notes',
            '/issues?query_id=1', '/projects/ecommerce/issues?query_id=8', '/issues?sort=assigned_to,cf_1:desc,fixed_version&per_page=50',
            '/issues.atom', '/issues.json', '/issues.xml', '/queries/new', '/projects/ecommerce/queries/new?f[]=status_id&op[status_id]=*&gantt=1',
            '/issues/calendar', '/projects/ecommerce/issues/calendar?month=12&year=2026', '/queries.json', '/queries/filter?type=IssueQuery&name=assigned_to_id&project_id=1',
        ];
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            foreach ($urls as $url) {
                $this->get($url);
                self::assertSame(200, $this->client->getResponse()->getStatusCode(), "$login $url");
            }
        }
    }

    public function testIssueListMarkup(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/projects/ecommerce/issues');
        self::assertCount(5, $crawler->filter('table.list.issues tbody tr.hascontextmenu'));
        self::assertSame('issue-17', $crawler->filter('table.list.issues tbody tr')->first()->attr('id'));
        self::assertCount(1, $crawler->filter('#filters-table'));
        self::assertStringContainsString('addFilter("status_id", "o", [""])', $this->client->getResponse()->getContent());
        self::assertCount(5, $crawler->filter('#sidebar ul.queries li'));
        $crawler = $this->client->request('GET', '/projects/ecommerce/issues?set_filter=1&group_by=status&t[]=estimated_hours');
        self::assertCount(2, $crawler->filter('tr.group'), 'open issues: New and In Progress');
        self::assertSame('14:00', $crawler->filter('p.query-totals .value')->text());
    }

    public function testCsvExport(): void
    {
        $this->login('admin');
        $csv = $this->get('/issues.csv?set_filter=1&f[]=status_id&op[status_id]=*&c[]=all_inline&encoding=UTF-8');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith("\xEF\xBB\xBF#,Project,Tracker", $csv);
        self::assertStringContainsString('16,eCommerce,Feature,14,Wishlist,New,Urgent,Wishlist emails', $csv);
        self::assertStringContainsString('attachment; filename="issues.csv"', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testApi(): void
    {
        $this->client->request('GET', '/issues.json?status_id=*&limit=2&offset=1&include=relations', [], [], ['HTTP_X_REDMINE_API_KEY' => '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(7, $data['total_count']);
        self::assertSame([18, 17], array_column($data['issues'], 'id'));
        self::assertSame(1, $data['offset']);
        self::assertSame(2, $data['limit']);
        self::assertArrayHasKey('relations', $data['issues'][1]);
        $this->client->request('GET', '/issues.json?set_filter=1&f[]=subject&op[subject]=~', [], [], ['HTTP_X_REDMINE_API_KEY' => '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(['Subject cannot be blank'], json_decode((string) $this->client->getResponse()->getContent(), true)['errors']);
    }

    public function testSavedQueryCrud(): void
    {
        $this->login('jsmith');
        $token = $this->token();
        $this->client->request('POST', '/projects/ecommerce/queries', [
            '_token' => $token, 'type' => 'IssueQuery',
            'query' => ['name' => 'My bugs', 'visibility' => '1', 'role_ids' => ['3', ''], 'group_by' => 'status', 'sort_criteria' => [['priority', 'desc'], ['', '']]],
            'f' => ['tracker_id', ''], 'op' => ['tracker_id' => '='], 'v' => ['tracker_id' => ['1']], 'c' => ['tracker', 'subject'], 't' => ['estimated_hours', ''],
        ]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $q = $this->em->getRepository(IssueQueryRecord::class)->findOneBy(['name' => 'My bugs']);
        self::assertNotNull($q);
        self::assertStringEndsWith('/projects/ecommerce/issues?query_id='.$q->id, (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(['tracker_id' => ['operator' => '=', 'values' => ['1']]], $q->filters);
        self::assertSame(['tracker', 'subject'], $q->columnNames);
        self::assertSame([['priority', 'desc']], $q->sortCriteria);
        self::assertSame('status', $q->groupBy);
        self::assertSame(1, $q->visibility);
        self::assertSame(['estimated_hours'], $q->getOption('totalable_names'));
        self::assertSame('ecommerce', $q->project?->identifier);

        $crawler = $this->client->request('GET', '/projects/ecommerce/issues?query_id='.$q->id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('My bugs', $crawler->filter('#content h2')->text());

        $this->get('/queries/'.$q->id.'/edit');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/queries/'.$q->id, ['_method' => 'put', '_token' => $token, 'query' => ['name' => 'Renamed', 'visibility' => '0'], 'f' => ['status_id'], 'op' => ['status_id' => 'o'], 'default_columns' => '1']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        $q = $this->em->find(IssueQueryRecord::class, $q->id);
        self::assertSame('Renamed', $q->name);
        self::assertNull($q->columnNames);
        self::assertSame(0, $q->visibility);

        // invalid: blank name
        $this->client->request('POST', '/queries/'.$q->id, ['_method' => 'put', '_token' => $token, 'query' => ['name' => '']]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('errorExplanation', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/queries/'.$q->id, ['_method' => 'delete', '_token' => $token]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertNull($this->em->find(IssueQueryRecord::class, $q->id));

        // jsmith cannot edit global public queries
        $this->get('/queries/1/edit');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testFilterValuesEndpoint(): void
    {
        $this->login('jsmith');
        $values = json_decode($this->get('/queries/filter?type=IssueQuery&name=assigned_to_id&project_id=1'), true);
        self::assertSame(['<< me >>', 'me'], $values[0]);
        self::assertContains('John Smith', array_column($values, 0));
        self::assertSame([], json_decode($this->get('/queries/filter?type=IssueQuery&name=unknown'), true));
    }
}
