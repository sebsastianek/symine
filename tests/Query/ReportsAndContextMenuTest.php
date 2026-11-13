<?php

declare(strict_types=1);

namespace App\Tests\Query;

use App\Controller\ContextMenusController;
use App\Controller\ReportsController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue summary report (ReportsController) and issues context menu (ContextMenusController),
 * checked against the HTML of the reference Redmine instance on the seeded data.
 */
final class ReportsAndContextMenuTest extends WebTestCase
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

    public function testAggregate(): void
    {
        $data = [
            ['status_id' => '1', 'closed' => false, 'tracker_id' => '1', 'total' => '2'],
            ['status_id' => '5', 'closed' => true, 'tracker_id' => '1', 'total' => '1'],
            ['status_id' => '1', 'closed' => false, 'tracker_id' => '2', 'total' => '3'],
        ];
        self::assertSame(6, ReportsController::aggregate($data, []));
        self::assertSame(2, ReportsController::aggregate($data, ['tracker_id' => 1, 'closed' => false]));
        self::assertSame(1, ReportsController::aggregate($data, ['tracker_id' => 1, 'closed' => true]));
        self::assertSame(5, ReportsController::aggregate($data, ['status_id' => 1]));
    }

    public function testIssueReportMatchesRedmine(): void
    {
        $this->login('admin');
        $html = $this->get('/projects/ecommerce/issues/report');
        self::assertResponseIsSuccessful();
        // tracker table (subproject issues included: display_subprojects_issues = 1)
        self::assertStringContainsString('<td class="name"><a href="/projects/ecommerce/issues?set_filter=1&amp;tracker_id=1">Bug</a></td>', $html);
        self::assertStringContainsString('<td><a href="/projects/ecommerce/issues?set_filter=1&amp;status_id=o&amp;tracker_id=1">2</a></td>', $html);
        self::assertStringContainsString('<td><a href="/projects/ecommerce/issues?set_filter=1&amp;status_id=c&amp;tracker_id=3">1</a></td>', $html);
        // "none" rows use !*
        self::assertStringContainsString('<a href="/projects/ecommerce/issues?assigned_to_id=%21%2A&amp;set_filter=1&amp;status_id=o">3</a>', $html);
        // subproject section links to the subproject
        self::assertStringContainsString('<a href="/projects/checkout/issues?project_id=3&amp;set_filter=1">Checkout</a>', $html);
        self::assertMatchesRegularExpression('#<tr class="total">\s*<td>Total</td>\s*<td>5</td>\s*<td>1</td>\s*<td>6</td>#', $html);
    }

    public function testIssueReportDetails(): void
    {
        $this->login('jsmith');
        foreach (['tracker', 'version', 'priority', 'category', 'assigned_to', 'author', 'subproject'] as $detail) {
            $this->get('/projects/ecommerce/issues/report/'.$detail);
            self::assertResponseIsSuccessful($detail);
        }
        $html = $this->get('/projects/ecommerce/issues/report/tracker');
        self::assertStringContainsString('<td><a href="/projects/ecommerce/issues?set_filter=1&amp;status_id=5&amp;tracker_id=3">1</a></td>', $html);
        self::assertStringContainsString('data-reports--details-title-value="Issues by Tracker"', $html);
        $this->get('/projects/ecommerce/issues/report/unknown');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReportCsvForAdmin(): void
    {
        $this->login('admin');
        $csv = $this->get('/projects/ecommerce/issues/report/tracker.csv?encoding=UTF-8');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("\xEF\xBB\xBF\"\",New,In Progress,Resolved,Feedback,Closed,Rejected,open,closed,Total\n", $csv);
        self::assertStringContainsString("Bug,1,1,0,0,0,0,2,0,2\n", $csv);
    }

    public function testReportRequiresViewIssues(): void
    {
        $this->get('/projects/internal/issues/report');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    public function testToQueryLikeRails(): void
    {
        self::assertSame('back_url=%2Fissues&ids%5B%5D=13&ids%5B%5D=14&issue%5Bstatus_id%5D=1',
            ContextMenusController::toQuery(['issue' => ['status_id' => 1], 'ids' => [13, 14], 'back_url' => '/issues']));
    }

    public function testContextMenuSingleIssue(): void
    {
        $this->login('admin');
        $html = $this->get('/issues/context_menu?ids[]=13&back_url=/projects/ecommerce/issues');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('<ul>', ltrim($html));
        self::assertStringContainsString('<a class="icon icon-edit" href="/issues/13/edit">', $html);
        self::assertStringContainsString('<a class="" rel="nofollow" data-method="patch" href="/issues/13?back_url=%2Fprojects%2Fecommerce%2Fissues&amp;ids%5B%5D=13&amp;issue%5Bstatus_id%5D=3">Resolved</a>', $html);
        self::assertStringContainsString('<a onclick="return false;" class="icon disabled" href="#">', $html); // current status
        self::assertStringContainsString('issue%5Bcustom_field_values%5D%5B1%5D=MySQL">MySQL</a>', $html);
        self::assertStringContainsString('<li class="folder list_cf cf_1">', $html);
        self::assertStringContainsString('href="/projects/ecommerce/issues/13/copy"', $html);
        self::assertStringContainsString('data-method="delete" href="/issues?back_url=%2Fprojects%2Fecommerce%2Fissues&amp;ids%5B%5D=13"', $html);
    }

    public function testContextMenuBulk(): void
    {
        $this->login('jsmith');
        $html = $this->get('/issues/context_menu?ids[]=13&ids[]=14&back_url=/issues');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('href="/issues/bulk_edit?ids%5B%5D=13&amp;ids%5B%5D=14"', $html);
        self::assertStringContainsString('href="/issues/bulk_update?back_url=%2Fissues&amp;ids%5B%5D=13&amp;ids%5B%5D=14&amp;issue%5Btracker_id%5D=2"', $html);
        self::assertStringContainsString('href="/projects/ecommerce/issues?issue_id=13%2C14&amp;set_filter=1&amp;status_id=%2A"', $html);
        // parent issue 14 has a derived priority: no priority folder
        self::assertStringNotContainsString('issue%5Bpriority_id%5D', $html);
    }

    public function testContextMenuWithoutDeleteOutsideLists(): void
    {
        $this->login('admin');
        $html = $this->get('/issues/context_menu?ids[]=13&back_url=/issues/13');
        self::assertStringNotContainsString('icon-del', $html);
    }

    public function testContextMenuNotFoundAndHiddenIssue(): void
    {
        $this->login('jsmith');
        $this->get('/issues/context_menu?ids[]=99999');
        self::assertResponseStatusCodeSame(404);
        $this->login('dlopper');
        $this->get('/issues/context_menu?ids[]=17'); // private issue not visible by dlopper
        self::assertResponseStatusCodeSame(403);
    }
}
