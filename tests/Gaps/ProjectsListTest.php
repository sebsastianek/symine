<?php

declare(strict_types=1);

namespace App\Tests\Gaps;

use App\Entity\Project;
use App\Entity\ProjectAdminQuery;
use App\Tests\Projects\ProjectsWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Redmine 7 projects lists: ProjectAdminQuery based /admin/projects (filters, columns, sort, grouping,
 * saved queries), projects context menu, bulk destroy, last activity column, CSV export and
 * routes compatibility (/projects/:id/edit, /projects/autocomplete).
 */
final class ProjectsListTest extends ProjectsWebTestCase
{
    private function crawler(string $url, array $server = []): Crawler
    {
        return $this->client->request('GET', $url, [], [], $server);
    }

    /** @return list<string> ids of the listed project rows */
    private function rowIds(Crawler $crawler): array
    {
        return $crawler->filter('table.list.projects tbody tr[id^="project-"]')->each(static fn (Crawler $tr) => (string) $tr->attr('id'));
    }

    // --- admin projects list ------------------------------------------------------------------

    public function testAdminListDefaultsToActiveProjectsWithRedmineMarkup(): void
    {
        $this->login('admin');
        $crawler = $this->crawler('/admin/projects');
        self::assertSame(200, $this->code());
        // tree order (lft), default status filter = active
        self::assertSame(['project-2', 'project-1', 'project-3'], $this->rowIds($crawler));
        $headers = $crawler->filter('table.list.projects thead th')->each(static fn (Crawler $th) => $th->attr('class'));
        self::assertSame(['checkbox hide-when-print', 'name', 'identifier', 'short_description', null], $headers);
        self::assertSame('/admin/projects_context_menu', $crawler->filter('form[data-cm-url]')->attr('data-cm-url'));
        self::assertCount(3, $crawler->filter('tr.hascontextmenu td.checkbox input[name="ids[]"]'));
        self::assertCount(3, $crawler->filter('tr.hascontextmenu td.buttons a.js-contextmenu'));
        self::assertSame('project-3', $crawler->filter('tr.idnt-1')->attr('id'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('addFilter("status", "=", ["1"]);', $html);
        self::assertStringContainsString('"status":{"type":"list","name":"Status","remote":true,"values":[["active","1"],["closed","5"],["archived","9"],["scheduled for deletion","10"]]}', $html);
        self::assertStringContainsString('var filtersUrl = "\/queries\/filter?type=ProjectAdminQuery";', $html);
        self::assertStringContainsString('<input type="hidden" name="type" id="query_type" value="ProjectAdminQuery" disabled="disabled" autocomplete="off" />', $html);
        self::assertStringContainsString('/assets/context_menu.js', $html);
        // CSV export options modal posting to /projects.csv
        self::assertSame('/projects.csv', $crawler->filter('#csv-export-options form#csv-export-form')->attr('action'));
        self::assertSame(1, $crawler->filter('#admin-menu a.projects.selected')->count());
    }

    public function testAdminListShowsAllStatusesAndHidesNothing(): void
    {
        $this->login('admin');
        $crawler = $this->crawler('/admin/projects?status=9');
        self::assertSame(['project-4'], $this->rowIds($crawler));
        self::assertStringContainsString('archived', (string) $crawler->filter('#project-4')->attr('class'));
        // archived project name is not a link
        self::assertSame(0, $crawler->filter('#project-4 td.name a')->count());

        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=status&op[status]=%3D&v[status][]=1&v[status][]=9&c[]=name&c[]=status&c[]=is_public&c[]=parent_id');
        self::assertSame(['project-2', 'project-4', 'project-1', 'project-3'], $this->rowIds($crawler));
        self::assertSame('active', trim($crawler->filter('#project-1 td.status')->text()));
        // ProjectsQueriesHelper only labels active / closed statuses
        self::assertSame('', trim($crawler->filter('#project-4 td.status')->text()));
        self::assertSame('Yes', trim($crawler->filter('#project-1 td.is_public')->text()));
        self::assertSame('eCommerce', trim($crawler->filter('#project-3 td.parent_id a')->text()));

        // scheduled for deletion projects have no checkbox / context menu
        $this->em->getConnection()->executeStatement('UPDATE projects SET status = 10 WHERE id = 2');
        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=status&op[status]=%3D&v[status][]=10');
        self::assertSame(['project-2'], $this->rowIds($crawler));
        self::assertSame(0, $crawler->filter('#project-2 input[name="ids[]"]')->count());
        self::assertSame(0, $crawler->filter('#project-2 a.js-contextmenu')->count());
    }

    public function testAdminListSortGroupAndFilters(): void
    {
        $this->login('admin');
        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=&c[]=name&c[]=identifier&sort=identifier:desc');
        self::assertSame(['project-4', 'project-2', 'project-1', 'project-3'], $this->rowIds($crawler));
        self::assertStringContainsString('sort desc', (string) $crawler->filter('th.identifier a')->attr('class'));
        self::assertStringContainsString('sort-by-identifier sort-desc', (string) $crawler->filter('table.list.projects')->attr('class'));

        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=&c[]=name&c[]=is_public&group_by=is_public');
        $groups = $crawler->filter('tr.group')->each(static fn (Crawler $tr) => [trim($tr->filter('.name')->text()), trim($tr->filter('.count')->text())]);
        self::assertSame([['No', '1'], ['Yes', '3']], $groups);

        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=name&op[name]=%7E&v[name][]=comm&c[]=name');
        self::assertSame(['project-1'], $this->rowIds($crawler));

        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=parent_id&op[parent_id]=%3D&v[parent_id][]=1&c[]=name');
        self::assertSame(['project-3'], $this->rowIds($crawler));

        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=is_public&op[is_public]=%3D&v[is_public][]=0&c[]=name');
        self::assertSame(['project-2'], $this->rowIds($crawler));

        // custom field column
        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=status&op[status]=%3D&v[status][]=1&c[]=name&c[]=cf_5&sort=cf_5:desc');
        self::assertSame('project-1', $this->rowIds($crawler)[0]);
        self::assertSame('ACME Corp', trim($crawler->filter('#project-1 td.cf_5')->text()));

        // invalid filter
        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=created_on&op[created_on]=%3D&v[created_on][]=foo');
        self::assertSame(200, $this->code());
        self::assertSame(1, $crawler->filter('#errorExplanation')->count());
        self::assertSame(0, $crawler->filter('table.list.projects')->count());
    }

    public function testAdminListIsAdminOnly(): void
    {
        $this->login('jsmith');
        $this->get('/admin/projects');
        self::assertSame(403, $this->code());
        $this->get('/admin/projects_context_menu?ids[]=1');
        self::assertSame(403, $this->code());
    }

    public function testSavedAdminQueries(): void
    {
        $this->login('admin');
        $this->submit('/queries', ['type' => 'ProjectAdminQuery', 'query' => ['name' => 'Archived projects', 'visibility' => '0'],
            'set_filter' => '1', 'f' => ['status', ''], 'op' => ['status' => '='], 'v' => ['status' => ['9']], 'c' => ['name', 'status']]);
        self::assertSame(302, $this->code());
        $record = $this->em->getRepository(ProjectAdminQuery::class)->findOneBy(['name' => 'Archived projects']);
        self::assertNotNull($record);
        self::assertSame('/admin/projects?query_id='.$record->id, $this->client->getResponse()->headers->get('Location'));

        $crawler = $this->crawler('/admin/projects?query_id='.$record->id);
        self::assertSame('Archived projects', trim($crawler->filter('#content h2')->text()));
        self::assertSame(['project-4'], $this->rowIds($crawler));
        self::assertSame(['checkbox hide-when-print', 'name', 'status', null], $crawler->filter('table.list.projects thead th')->each(static fn (Crawler $th) => $th->attr('class')));
        self::assertSame('Archived projects', trim($crawler->filter('#sidebar ul.queries a.query.selected')->text()));
        self::assertSame(1, $crawler->filter('p.buttons a.icon-edit[href="/queries/'.$record->id.'/edit"]')->count());

        // ProjectAdminQuery.visible: every admin query is visible to admins (even private ones of other admins)
        $this->em->getConnection()->executeStatement('UPDATE queries SET user_id = 999 WHERE id = ?', [$record->id]);
        $crawler = $this->crawler('/admin/projects');
        self::assertSame(1, $crawler->filter('#sidebar ul.queries a.query')->count());
        // admin queries are not listed with the public project queries
        $html = $this->get('/projects');
        self::assertStringNotContainsString('Archived projects', $html);
    }

    // --- context menu -------------------------------------------------------------------------

    public function testContextMenuSingleProject(): void
    {
        $this->login('admin');
        $html = $this->get('/admin/projects_context_menu?ids[]=2&back_url=%2Fadmin%2Fprojects');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<a data-confirm="Are you sure you want to archive the &#039;Internal Tools&#039; project?" class="icon icon-lock" rel="nofollow" data-method="post" href="/projects/internal/archive">', $html);
        self::assertStringContainsString('<a class="icon icon-copy" href="/projects/internal/copy">', $html);
        self::assertStringContainsString('<a class="icon icon-del" rel="nofollow" data-method="delete" href="/projects/internal?back_url=%2Fadmin%2Fprojects">', $html);
        self::assertStringNotContainsString('<html', $html);

        $html = $this->get('/admin/projects_context_menu?ids[]=4');
        self::assertStringContainsString('<a class="icon icon-unlock" rel="nofollow" data-method="post" href="/projects/legacy/unarchive">', $html);

        $this->client->request('POST', '/admin/projects_context_menu', ['ids' => ['2'], '_token' => $this->csrf('/admin/projects')]);
        self::assertSame(200, $this->code());
    }

    public function testContextMenuMultipleProjects(): void
    {
        $this->login('admin');
        $html = $this->get('/admin/projects_context_menu?ids[]=2&ids[]=3&back_url=%2Fadmin%2Fprojects');
        self::assertStringContainsString('data-confirm="Are you sure you want to delete the selected projects and related data?" class="icon icon-del" rel="nofollow" data-method="delete" href="http://localhost/projects/bulk_destroy?back_url=%2Fadmin%2Fprojects&amp;ids%5B%5D=2&amp;ids%5B%5D=3"', $html);
        self::assertStringNotContainsString('icon-lock', $html);
        $this->get('/admin/projects_context_menu?ids[]=999');
        self::assertSame(404, $this->code());
        $this->get('/admin/projects_context_menu');
        self::assertSame(404, $this->code());
    }

    // --- bulk destroy -------------------------------------------------------------------------

    public function testBulkDestroyConfirmationAndDeletion(): void
    {
        $this->login('admin');
        $token = $this->csrf('/admin/projects');
        $crawler = $this->client->request('POST', '/projects/bulk_destroy?ids[]=2&ids[]=1', ['_method' => 'DELETE', '_token' => $token]);
        self::assertSame(200, $this->code());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<form action="/projects/bulk_destroy?ids%5B%5D=1&amp;ids%5B%5D=2" accept-charset="UTF-8" method="post"><input type="hidden" name="_method" value="delete" autocomplete="off" />', $html);
        self::assertStringContainsString("<p><p>You are about to permanently delete the following projects, including possible subprojects and any related data.\n<br />Please review", $html);
        self::assertStringContainsString('including its subproject(s): Checkout', $html);
        self::assertStringContainsString('To confirm, please enter &quot;Yes&quot; in the box below.', $html);
        self::assertSame(['eCommerce', 'Internal Tools'], $crawler->filter('.warning p strong')->each(static fn (Crawler $s) => $s->text()));
        self::assertSame(1, $crawler->filter('input#confirm[name="confirm"]')->count());
        self::assertSame('/admin/projects', $crawler->filter('#content form a')->attr('href'));
        self::assertStringContainsString('action-bulk_destroy', (string) $crawler->filter('body')->attr('class'));

        // wrong confirmation: nothing deleted
        $this->client->request('POST', '/projects/bulk_destroy?ids[]=2', ['_method' => 'DELETE', '_token' => $token, 'confirm' => 'yes']);
        self::assertSame(200, $this->code());
        $this->em->clear();
        self::assertNotNull($this->em->find(Project::class, 2));

        $this->client->request('POST', '/projects/bulk_destroy?ids[]=2&ids[]=4', ['_method' => 'DELETE', '_token' => $token, 'confirm' => 'Yes']);
        self::assertSame(302, $this->code());
        self::assertSame('/admin/projects', $this->client->getResponse()->headers->get('Location'));
        $this->em->clear();
        self::assertNull($this->em->find(Project::class, 2));
        self::assertNull($this->em->find(Project::class, 4));
        self::assertNotNull($this->em->find(Project::class, 1));
        $html = $this->get('/admin/projects');
        self::assertStringContainsString('Successful deletion.', $html);

        // a parent and its subproject selected together
        $this->client->request('POST', '/projects/bulk_destroy?ids[]=1&ids[]=3', ['_method' => 'DELETE', '_token' => $token, 'confirm' => 'Yes']);
        self::assertSame(302, $this->code());
        $this->em->clear();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM projects'));
    }

    public function testBulkDestroyErrors(): void
    {
        $this->login('admin');
        $token = $this->csrf('/admin/projects');
        $this->client->request('POST', '/projects/bulk_destroy?ids[]=999', ['_method' => 'DELETE', '_token' => $token]);
        self::assertSame(404, $this->code());
        $this->em->getConnection()->executeStatement('UPDATE projects SET status = 10 WHERE id = 2');
        $this->client->request('POST', '/projects/bulk_destroy?ids[]=2', ['_method' => 'DELETE', '_token' => $token]);
        self::assertSame(404, $this->code());

        $this->login('jsmith');
        $this->client->request('POST', '/projects/bulk_destroy?ids[]=1', ['_method' => 'DELETE', '_token' => $this->csrf('/projects'), 'confirm' => 'Yes']);
        self::assertSame(403, $this->code());
        $this->em->clear();
        self::assertNotNull($this->em->find(Project::class, 1));
    }

    // --- last activity / CSV ------------------------------------------------------------------

    public function testLastActivityColumn(): void
    {
        $this->login('admin');
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE issues SET created_on = '2020-01-01 10:00:00'");
        $conn->executeStatement("UPDATE journals SET created_on = '2020-01-01 10:00:00'");
        $conn->executeStatement("UPDATE news SET created_on = '2020-01-01 10:00:00'");
        $conn->executeStatement("UPDATE time_entries SET created_on = '2020-01-01 10:00:00'");
        $conn->executeStatement("UPDATE documents SET created_on = '2020-01-01 10:00:00'");
        $conn->executeStatement("UPDATE attachments SET created_on = '2020-01-01 10:00:00'");
        $conn->executeStatement("UPDATE messages SET created_on = '2020-01-01 10:00:00'");
        $conn->executeStatement("UPDATE wiki_content_versions SET updated_on = '2020-01-01 10:00:00'");
        $conn->executeStatement("UPDATE changesets SET committed_on = '2020-01-01 10:00:00'");
        $issueId = (int) $conn->fetchOne('SELECT id FROM issues WHERE project_id = 3 ORDER BY id LIMIT 1');
        if ($issueId > 0) {
            $conn->executeStatement("UPDATE issues SET created_on = '2021-03-04 12:00:00' WHERE id = ?", [$issueId]);
        } else {
            $conn->executeStatement("UPDATE news SET created_on = '2021-03-04 12:00:00', project_id = 3 WHERE id = (SELECT MIN(id) FROM news)");
        }

        $crawler = $this->crawler('/projects?set_filter=1&f[]=status&op[status]=%3D&v[status][]=1&display_type=list&c[]=name&c[]=last_activity_date');
        self::assertSame(200, $this->code());
        // not sortable
        self::assertSame(0, $crawler->filter('th.last_activity_date a')->count());
        $link = $crawler->filter('#project-3 td.last_activity_date a');
        self::assertSame('/projects/checkout/activity?from=2021-03-04', $link->attr('href'));
        self::assertSame('03/04/2021 12:00 PM', trim($link->text()));

        // admin list too
        $crawler = $this->crawler('/admin/projects?set_filter=1&f[]=&c[]=name&c[]=last_activity_date');
        self::assertSame('/projects/checkout/activity?from=2021-03-04', $crawler->filter('#project-3 td.last_activity_date a')->attr('href'));
        self::assertSame('', trim($crawler->filter('#project-4 td.last_activity_date')->text()));

        // anonymous users don't see activity of private projects
        $this->client->restart();
        $crawler = $this->crawler('/projects?set_filter=1&f[]=&display_type=list&c[]=name&c[]=last_activity_date');
        self::assertSame(0, $crawler->filter('#project-2')->count());
    }

    public function testCsvExport(): void
    {
        $this->login('admin');
        $crawler = $this->crawler('/projects?set_filter=1&f[]=status&op[status]=%3D&v[status][]=1&display_type=list&c[]=name&c[]=identifier');
        $csvLink = $crawler->filter('p.other-formats a.csv');
        self::assertSame('/projects.csv?c%5B%5D=name&c%5B%5D=identifier&display_type=list&f%5B%5D=status&op%5Bstatus%5D=%3D&set_filter=1&v%5Bstatus%5D%5B%5D=1', $csvLink->attr('href'));
        self::assertSame('/projects.csv', $crawler->filter('#csv-export-options form')->attr('action'));
        $hidden = $crawler->filter('#csv-export-options form input[type=hidden]')->each(static fn (Crawler $i) => $i->attr('name').'='.$i->attr('value'));
        self::assertSame(['set_filter=1', 'f[]=status', 'op[status]==', 'v[status][]=1', 'c[]=name', 'c[]=identifier'], $hidden);
        // board display: no CSV link
        $crawler = $this->crawler('/projects?set_filter=1&display_type=board');
        self::assertSame(0, $crawler->filter('p.other-formats a.csv')->count());

        $this->client->request('GET', '/projects.csv?set_filter=1&f[]=status&op[status]=%3D&v[status][]=1&c[]=all_inline&encoding=UTF-8&field_separator=%2C');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/csv; header=present', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('filename="projects.csv"', (string) $response->headers->get('Content-Disposition'));
        $lines = explode("\n", trim((string) $response->getContent()));
        self::assertSame("\xEF\xBB\xBFName,Status,Description,Homepage,Identifier,Subproject of,Public,Created,Updated,Last activity,Customer", $lines[0]);
        self::assertCount(4, $lines);
        self::assertStringStartsWith('Internal Tools,active,"","",internal,,No,', $lines[1]);
        self::assertStringStartsWith('eCommerce,active,Online **shop** project,"",ecommerce,,Yes,', $lines[2]);
        self::assertStringEndsWith(',ACME Corp', $lines[2]);
        self::assertStringStartsWith('Checkout,active,"","",checkout,eCommerce,Yes,', $lines[3]);

        // selected columns, semicolon, default encoding
        $this->client->request('GET', '/projects.csv?set_filter=1&f[]=&c[]=name&c[]=identifier&c[]=&field_separator=%3B&encoding=ISO-8859-1');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame("Name;Identifier\nInternal Tools;internal\neCommerce;ecommerce\nCheckout;checkout\n", $content);

        // default query + visibility (anonymous: public projects only)
        $this->client->restart();
        $this->client->request('GET', '/projects.csv');
        self::assertSame(200, $this->code());
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('eCommerce', $content);
        self::assertStringNotContainsString('Internal Tools', $content);
    }

    // --- routes -------------------------------------------------------------------------------

    public function testEditRouteBehavesLikeRedmine(): void
    {
        $this->login('admin');
        $this->get('/projects/ecommerce/edit', ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
        self::assertSame(406, $this->code());
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        $this->get('/projects/ecommerce/edit', ['HTTP_ACCEPT' => '*/*']);
        self::assertSame(204, $this->code());
        $this->get('/projects/ecommerce/edit', ['HTTP_ACCEPT' => 'text/html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(204, $this->code());
        $this->get('/projects/nonexistent/edit');
        self::assertSame(404, $this->code());

        $this->login('dlopper');
        $this->get('/projects/internal/edit', ['HTTP_ACCEPT' => '*/*']);
        self::assertSame(403, $this->code());

        $this->client->restart();
        $this->get('/projects/ecommerce/edit');
        self::assertSame(302, $this->code());
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAutocompleteWithoutJsSuffix(): void
    {
        $this->login('jsmith');
        $js = $this->get('/projects/autocomplete?q=check', ['HTTP_ACCEPT' => 'text/javascript, application/javascript, */*; q=0.01', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $this->code());
        self::assertStringStartsWith('text/javascript', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame("$('#project-jump .drdn-items.projects').html('<strong>Results<\\/strong><a title=\\\"Checkout\\\" href=\\\"/projects/checkout?jump=overview\\\"><span style=\\\"padding-inline-start:0px;\\\">Checkout<\\/span><\\/a>');\n", $js);
        $this->get('/projects/autocomplete?q=check', ['HTTP_ACCEPT' => '*/*']);
        self::assertSame(200, $this->code());
        // a browser navigation asks for HTML: 406 like respond_to { format.js }
        $this->get('/projects/autocomplete?q=check', ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,*/*;q=0.8']);
        self::assertSame(406, $this->code());
        // .js suffix keeps working whatever the Accept header
        $js = $this->get('/projects/autocomplete.js?q=check&jump=issues');
        self::assertStringContainsString('href=\\"/projects/checkout?jump=issues\\"', $js);
        $js = $this->get('/projects/autocomplete.js?q=zzz');
        self::assertSame("$('#project-jump .drdn-items.projects').html('<span>No data to display<\\/span>');\n", $js);
    }
}
