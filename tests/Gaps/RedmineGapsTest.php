<?php

declare(strict_types=1);

namespace App\Tests\Gaps;

use App\Tests\Content\ContentWebTestCase;

/**
 * Remaining Redmine features / route gaps: reactions, wiki syntax help, wiki pages autocomplete,
 * health check, robots.txt, attachment and membership routes, issue previous/next navigation,
 * bulk edit form refresh.
 */
final class RedmineGapsTest extends ContentWebTestCase
{
    private function setting(string $name, string $value): void
    {
        static::getContainer()->get(\App\Service\Settings::class)->set($name, $value);
    }

    private function projectStatus(int $id, int $status): void
    {
        $project = $this->em->find(\App\Entity\Project::class, $id);
        $project->status = $status;
        $this->em->flush();
    }

    /** the Localizer memoizes the locale of the first request (kernel not rebooted between requests) */
    private function resetLocale(): void
    {
        static::getContainer()->get(\App\I18n\Localizer::class)->setLocale(null);
    }

    private function userLanguage(string $login, string $language): void
    {
        $user = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['login' => $login]);
        $user->language = $language;
        $this->em->flush();
    }

    private function xhr(string $method, string $url, array $params = []): string
    {
        $token = $this->csrf('/');
        $this->client->request($method, $url, $params, [], [
            'HTTP_X_CSRF_TOKEN' => $token,
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'text/javascript, application/javascript, application/ecmascript, application/x-ecmascript, */*; q=0.01',
        ]);

        return (string) $this->client->getResponse()->getContent();
    }

    // --- health check / robots -------------------------------------------------------------

    public function testHealthCheck(): void
    {
        $html = $this->get('/up');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('background-color: green', $html);
        $json = json_decode($this->get('/up.json'), true);
        self::assertSame('up', $json['status']);
    }

    public function testRobots(): void
    {
        $txt = $this->get('/robots.txt');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("User-agent: *\n", $txt);
        self::assertStringContainsString("Disallow: /projects/ecommerce/repository\nDisallow: /projects/ecommerce/issues\nDisallow: /projects/ecommerce/activity\nDisallow: /projects/1/repository", $txt);
        self::assertStringNotContainsString('/projects/internal/', $txt);
        self::assertStringContainsString("Disallow: /issues?*sort=\n", $txt);
        self::assertStringContainsString("Disallow: /account/lost_password\n", $txt);
        $this->get('/robots.json');
        self::assertResponseStatusCodeSame(404);

        $this->setting('login_required', '1');
        self::assertSame("User-agent: *\nDisallow: /\n", $this->get('/robots.txt'));
    }

    // --- help ------------------------------------------------------------------------------

    public function testWikiSyntaxHelp(): void
    {
        $html = $this->get('/help/wiki_syntax');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<title>Wiki formatting</title>', $html);
        self::assertStringContainsString('(CommonMark Markdown (GitHub Flavored))', $html);
        self::assertStringContainsString('href="/help/wiki_syntax/detailed#5"', $html);
        self::assertStringContainsString('src="/assets/jstoolbar/bold.svg"', $html);
        self::assertStringNotContainsString('id="top-menu"', $html, 'rendered without layout');

        $html = $this->get('/help/wiki_syntax/detailed');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/assets/wiki_syntax_detailed.css', $html);
        self::assertStringContainsString('{{toc}}', $html);
        self::assertStringContainsString('href="/help/code_highlighting"', $html);

        $this->get('/help/wiki_syntax/other');
        self::assertResponseStatusCodeSame(404);

        $this->setting('text_formatting', 'textile');
        $html = $this->get('/help/wiki_syntax');
        self::assertStringContainsString('*Strong*', $html);
        self::assertStringNotContainsString('(CommonMark', $html);
    }

    public function testWikiSyntaxHelpIsLocalized(): void
    {
        $this->userLanguage('jsmith', 'de');
        $this->login('jsmith');
        $this->resetLocale();
        $html = $this->get('/help/wiki_syntax');
        self::assertStringContainsString('Wiki Syntax Schnellreferenz', $html);
        // no Czech CommonMark help: falls back to English
        $this->userLanguage('jsmith', 'cs');
        $this->login('jsmith');
        $this->resetLocale();
        self::assertStringContainsString('Wiki Syntax Quick Reference', $this->get('/help/wiki_syntax'));
    }

    public function testAllWikiSyntaxTemplatesRender(): void
    {
        $twig = static::getContainer()->get('twig');
        $files = glob(\dirname(__DIR__, 2).'/templates/help/wiki_syntax/*/*/wiki_syntax_*.html.twig') ?: [];
        self::assertCount(46, $files);
        foreach ($files as $file) {
            $name = substr($file, strpos($file, 'help/wiki_syntax/'));
            $html = $twig->render($name, ['asset_base' => '', 'code_highlighting_url' => '/help/code_highlighting', 'mermaid_version' => null]);
            self::assertStringContainsString('</html>', $html, $name);
            self::assertStringNotContainsString('<%', $html, $name);
        }
    }

    // --- autocomplete ----------------------------------------------------------------------

    public function testWikiPagesAutoComplete(): void
    {
        $this->login('jsmith');
        $json = json_decode($this->get('/wiki_pages/auto_complete?project_id=ecommerce&q='), true);
        self::assertSame([['id' => 2, 'label' => 'Checkout_flow', 'value' => 'Checkout_flow'], ['id' => 1, 'label' => 'Wiki', 'value' => 'Wiki']], $json);
        $json = json_decode($this->get('/wiki_pages/auto_complete?project_id=1&q=check'), true);
        self::assertSame(['Checkout_flow'], array_column($json, 'value'));
        self::assertSame([], json_decode($this->get('/wiki_pages/auto_complete?q=wiki'), true));
        $this->get('/wiki_pages/auto_complete?project_id=nope');
        self::assertResponseStatusCodeSame(404);

        $html = $this->get('/projects/ecommerce/issues/new');
        self::assertStringContainsString('"wiki_pages":"/wiki_pages/auto_complete?project_id=ecommerce&q="', str_replace('\/', '/', $html));
    }

    // --- route compatibility ---------------------------------------------------------------

    public function testAttachmentRoutesWithoutFilenameOrSize(): void
    {
        $this->login('jsmith');
        $this->get('/attachments/download/1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('notes.txt', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->get('/attachments/thumbnail/1');
        self::assertResponseStatusCodeSame(404); // not an image
        $this->get('/attachments/thumbnail/1/200');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPrincipalMembershipVerbs(): void
    {
        $this->login('admin');
        $memberId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM members WHERE user_id = 5 AND project_id = 2');
        $this->get('/users/5/memberships/'.$memberId.'/edit');
        self::assertResponseIsSuccessful();
        foreach (['PATCH', 'PUT'] as $verb) {
            self::assertSame(302, $this->submit('/users/5/memberships/'.$memberId, ['membership' => ['role_ids' => ['5']]], $verb));
        }
        self::assertSame('5', (string) $this->em->getConnection()->fetchOne('SELECT role_id FROM member_roles WHERE member_id = ?', [$memberId]));
        self::assertSame(302, $this->submit('/users/5/memberships/'.$memberId, [], 'DELETE'));
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM members WHERE id = ?', [$memberId]));
        $groupMember = (int) $this->em->getConnection()->fetchOne('SELECT id FROM members WHERE user_id = 10');
        self::assertSame(302, $this->submit('/groups/10/memberships/'.$groupMember, ['membership' => ['role_ids' => ['5']]], 'PATCH'));
        self::assertSame(302, $this->submit('/groups/10/memberships/'.$groupMember, [], 'DELETE'));
    }

    // --- reactions -------------------------------------------------------------------------

    public function testIssueReactions(): void
    {
        $this->login('jsmith');
        $html = $this->get('/issues/14');
        self::assertStringContainsString('<div class="reaction">', $html);
        self::assertStringContainsString('<span class="reaction-button-wrapper" data-reaction-button-id="reaction_issue_14"><a class="icon reaction-button" data-remote="true" rel="nofollow" data-method="post" href="/reactions?object_id=14&amp;object_type=Issue">', $html);
        self::assertStringContainsString('data-reaction-button-id="reaction_journal_13"', $html);

        $js = $this->xhr('POST', '/reactions?object_id=14&object_type=Issue');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/javascript', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString("$('[data-reaction-button-id=reaction_issue_14]')", $js);
        self::assertStringContainsString('reacted has-reactions', $js);
        self::assertStringContainsString('title=\"John Smith\"', $js);
        // find_or_create: posting twice keeps one reaction
        $this->xhr('POST', '/reactions?object_id=14&object_type=Issue');
        $id = (int) $this->em->getConnection()->fetchOne("SELECT id FROM reactions WHERE reactable_type = 'Issue' AND reactable_id = 14");
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM reactions'));

        $this->login('dlopper');
        $this->xhr('POST', '/reactions?object_id=14&object_type=Issue');
        $html = $this->get('/issues/14');
        self::assertStringContainsString('class="icon reaction-button reacted has-reactions" title="Dave Lopper and John Smith"', $html);
        self::assertStringContainsString('<span class="icon-label">2</span>', $html);

        // API
        $api = $this->api('GET', '/issues/14.json?include=reactions,journals');
        self::assertSame([['user' => ['id' => 6, 'name' => 'Dave Lopper']], ['user' => ['id' => 5, 'name' => 'John Smith']]], $api['issue']['reactions']);
        self::assertSame([], $api['issue']['journals'][0]['reactions']);
        self::assertArrayNotHasKey('reactions', $this->api('GET', '/issues/14.json')['issue']);
        $this->client->request('GET', '/issues/14.xml?include=reactions', [], [], ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY]);
        self::assertStringContainsString('<reactions type="array"><reaction><user id="6" name="Dave Lopper"/></reaction>', (string) $this->client->getResponse()->getContent());

        // only the own reaction can be removed
        $this->xhr('DELETE', '/reactions/'.$id.'?object_id=14&object_type=Issue');
        self::assertResponseIsSuccessful();
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM reactions'));
        $this->login('jsmith');
        $js = $this->xhr('DELETE', '/reactions/'.$id.'?object_id=14&object_type=Issue');
        self::assertStringContainsString('title=\"Dave Lopper\"', $js);
        self::assertStringContainsString('data-method=\"post\"', $js);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM reactions'));
    }

    public function testReactionsOnJournalsNewsCommentsAndMessages(): void
    {
        $this->login('jsmith');
        foreach ([['Journal', 13], ['News', 1], ['Comment', 1], ['Message', 1], ['Message', 2]] as [$type, $id]) {
            $js = $this->xhr('POST', "/reactions?object_id=$id&object_type=$type");
            self::assertResponseIsSuccessful("$type #$id");
            self::assertStringContainsString('reaction_'.strtolower($type).'_'.$id, $js);
        }
        self::assertStringContainsString('data-reaction-button-id="reaction_journal_13"><a class="icon reaction-button reacted has-reactions"', $this->get('/issues/14'));
        $news = $this->get('/news/1');
        self::assertStringContainsString('data-reaction-button-id="reaction_news_1"><a class="icon reaction-button reacted', $news);
        self::assertStringContainsString('data-reaction-button-id="reaction_comment_1"><a class="icon reaction-button reacted', $news);
        $topic = $this->get('/boards/1/topics/1');
        self::assertStringContainsString('data-reaction-button-id="reaction_message_1"><a class="icon reaction-button reacted', $topic);
        self::assertStringContainsString('data-reaction-button-id="reaction_message_2"><a class="icon reaction-button reacted', $topic);
        $api = $this->api('GET', '/news/1.json?include=reactions,comments');
        self::assertSame('John Smith', $api['news']['reactions'][0]['user']['name']);
        self::assertSame('John Smith', $api['news']['comments'][0]['reactions'][0]['user']['name']);
    }

    public function testReactionPermissions(): void
    {
        // anonymous: read-only button, cannot react
        $html = $this->get('/issues/14');
        self::assertStringContainsString('<span class="icon reaction-button readonly">', $html);
        $this->xhr('POST', '/reactions?object_id=14&object_type=Issue');
        self::assertNotSame(200, $this->httpStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM reactions'));

        $this->login('rhill');
        // invalid type
        $this->xhr('POST', '/reactions?object_id=1&object_type=Project');
        self::assertResponseStatusCodeSame(403);
        // not visible (private project of jsmith)
        $this->xhr('POST', '/reactions?object_id=18&object_type=Issue');
        self::assertResponseStatusCodeSame(403);
        // unknown object
        $this->xhr('POST', '/reactions?object_id=999&object_type=Issue');
        self::assertResponseStatusCodeSame(404);
        // html format is not supported
        $this->submit('/reactions?object_id=14&object_type=Issue');
        self::assertResponseStatusCodeSame(404);
        // closed project: read-only
        $this->projectStatus(1, 5);
        $this->xhr('POST', '/reactions?object_id=14&object_type=Issue');
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('reaction-button readonly', $this->get('/issues/14'));
        $this->projectStatus(1, 1);
        // disabled
        $this->setting('reactions_enabled', '0');
        $this->xhr('POST', '/reactions?object_id=14&object_type=Issue');
        self::assertResponseStatusCodeSame(403);
        $html = $this->get('/issues/14');
        self::assertStringNotContainsString('reaction-button', $html);
        self::assertStringContainsString('<div class="reaction">', $html);
    }

    public function testReactionsAreDeletedWithTheirIssue(): void
    {
        $this->login('jsmith');
        $this->xhr('POST', '/reactions?object_id=14&object_type=Issue');
        $this->xhr('POST', '/reactions?object_id=13&object_type=Journal');
        $this->xhr('POST', '/reactions?object_id=13&object_type=Issue');
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM reactions'));
        $this->login('admin');
        $this->submit('/issues/14', [], 'DELETE');
        self::assertSame(302, $this->httpStatus());
        self::assertSame([['Issue', 13]], array_map(static fn ($r) => [$r['reactable_type'], (int) $r['reactable_id']], $this->em->getConnection()->fetchAllAssociative('SELECT reactable_type, reactable_id FROM reactions')));
    }

    public function testReactionTooltipLimitsUserNames(): void
    {
        $conn = $this->em->getConnection();
        $now = date('Y-m-d H:i:s');
        for ($i = 0; $i < 12; ++$i) {
            $conn->executeStatement("INSERT INTO users (login, firstname, lastname, admin, status, type, created_on, updated_on, hashed_password) VALUES (?, 'U', ?, 0, 1, 'User', ?, ?, '')", ['u'.$i, 'N'.$i, $now, $now]);
            $uid = (int) $conn->lastInsertId();
            $conn->executeStatement("INSERT INTO reactions (reactable_type, reactable_id, user_id, created_at, updated_at) VALUES ('Issue', 14, ?, ?, ?)", [$uid, $now, $now]);
        }
        $this->login('admin');
        $html = $this->get('/issues/14');
        self::assertMatchesRegularExpression('/title="U N11, U N10, .*U N2, and 2 others"/', $html);
        self::assertStringContainsString('<span class="icon-label">12</span>', $html);
    }

    // --- issue navigation ------------------------------------------------------------------

    public function testIssuePreviousNextNavigation(): void
    {
        $this->login('jsmith');
        $this->get('/projects/ecommerce/issues?set_filter=1');
        $html = $this->get('/issues/14');
        self::assertStringContainsString('<div class="next-prev-links contextual">', $html);
        self::assertStringContainsString('<a title="#15" accesskey="p" href="/issues/15">« Previous</a>', $html);
        self::assertStringContainsString('<a title="#13" accesskey="n" href="/issues/13">Next »</a>', $html);
        self::assertMatchesRegularExpression('#<li class="page position">\s*<a href="/projects/ecommerce/issues\?[^"]*page=1[^"]*">4 of 5</a>#', $html);
        // the edit form carries the navigation over the update redirect
        self::assertStringContainsString('<input type="hidden" name="prev_issue_id" id="prev_issue_id" value="15"', $html);

        // first issue of the list
        $html = $this->get('/issues/17');
        self::assertStringContainsString('<li class="previous"', $html);
        self::assertStringContainsString('<span>« Previous</span>', $html);

        // sorted by id ascending
        $this->get('/projects/ecommerce/issues?set_filter=1&sort=id');
        $html = $this->get('/issues/14');
        self::assertStringContainsString('href="/issues/13">« Previous</a>', $html);
        self::assertStringContainsString('2 of 5', $html);

        // issue not in the query: no links
        $this->get('/projects/ecommerce/issues?set_filter=1&f[]=tracker_id&op[tracker_id]==&v[tracker_id][]=1');
        self::assertStringNotContainsString('next-prev-links', $this->get('/issues/14'));
    }

    public function testNavigationIsKeptAfterUpdate(): void
    {
        $this->login('jsmith');
        $status = $this->submit('/issues/14', ['issue' => ['notes' => 'hello'], 'prev_issue_id' => '15', 'next_issue_id' => '13', 'issue_position' => '3', 'issue_count' => '4'], 'PATCH');
        self::assertSame(302, $status);
        $html = $this->get('/issues/14');
        self::assertStringContainsString('href="/issues/15">« Previous</a>', $html);
        self::assertStringContainsString('<span>3 of 4</span>', $html);
    }

    // --- bulk edit refresh -----------------------------------------------------------------

    public function testBulkEditFormRefresh(): void
    {
        $this->login('jsmith');
        $html = $this->get('/issues/bulk_edit?ids[]=13&ids[]=14');
        self::assertStringContainsString('onchange="updateBulkEditFrom(', $html);
        $js = $this->xhr('POST', '/issues/bulk_edit.js', ['ids' => ['13', '14'], 'issue' => ['tracker_id' => '2', 'status_id' => '']]);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("$('#content').html(", $js);
        self::assertStringContainsString('bulk_edit_form', $js);
        $html = json_decode(substr($js, \strlen("$('#content').html("), -2));
        self::assertIsString($html);
        self::assertStringContainsString('<option value="2" selected="selected">Feature</option>', $html);
        self::assertStringNotContainsString('id="top-menu"', $html);
        // the issues themselves are left unchanged
        $this->em->clear();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT tracker_id FROM issues WHERE id = 13'));
    }
}
