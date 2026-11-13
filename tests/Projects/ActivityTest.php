<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Controller\ActivitiesController;
use App\Entity\User;
use App\Service\Activity\ActivityEvent;
use App\Service\Activity\ActivityFetcher;
use App\Twig\ActivityExtension;

final class ActivityTest extends ProjectsWebTestCase
{
    private const ALL = '?show_issues=1&show_changesets=1&show_news=1&show_documents=1&show_files=1&show_wiki_edits=1&show_messages=1&show_time_entries=1';

    public function testPagesRespondForUsers(): void
    {
        foreach (['/activity', '/projects/ecommerce/activity', '/activity'.self::ALL, '/activity?user_id=5', '/activity?from=2026-01-01'] as $url) {
            $this->get($url);
            self::assertSame(200, $this->code(), "anonymous $url");
        }
        $this->login('jsmith');
        foreach (['/activity', '/projects/ecommerce/activity', '/projects/internal/activity', '/activity'.self::ALL] as $url) {
            $html = $this->get($url);
            self::assertSame(200, $this->code(), "jsmith $url");
            self::assertStringContainsString('id="activity_scope_form"', $html);
        }
    }

    public function testEventsAreListedWithRedmineMarkup(): void
    {
        $this->login('admin');
        $html = $this->get('/activity'.self::ALL);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<div id="activity">', $html);
        self::assertStringContainsString('Support #19 (Closed): Customer cannot login', $html);
        self::assertStringContainsString('href="/issues/19#change-', $html);
        self::assertStringContainsString('Wiki edit: Checkout_flow (#2)', $html);
        self::assertStringContainsString('href="/news/1"', $html);
        self::assertMatchesRegularExpression('/<dt class="issue[^"]* grouped/', $html);
        self::assertStringContainsString('class="atom"', $html);
    }

    public function testPrivateProjectHiddenFromAnonymous(): void
    {
        $this->get('/projects/internal/activity');
        self::assertNotSame(200, $this->code());
        $html = $this->get('/activity'.self::ALL);
        self::assertStringNotContainsString('<span class="project">Internal Tools', $html);
    }

    public function testAuthorFilterAndScopeOfProject(): void
    {
        $this->login('admin');
        $html = $this->get('/activity?user_id=6');
        self::assertStringContainsString('Dave Lopper', $html);
        self::assertStringNotContainsString('Feature #16 (New)', $html, 'issue created by jsmith filtered out');
        $html = $this->get('/projects/ecommerce/activity?with_subprojects=0');
        self::assertStringNotContainsString('<span class="project">Checkout', $html);
        $html = $this->get('/projects/ecommerce/activity');
        self::assertStringContainsString('<span class="project">Checkout', $html);
    }

    public function testPagingLinks(): void
    {
        $html = $this->get('/activity?from=2026-01-10');
        self::assertStringContainsString('from=2025-12-31', $html, 'previous link');
        self::assertStringContainsString('class="next page"', $html);
    }

    public function testAtomFeed(): void
    {
        $this->client->request('GET', '/activity.atom');
        self::assertSame(200, $this->code());
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $xml);
        self::assertStringContainsString(': Activity</title>', $xml);
        $this->client->request('GET', '/projects/ecommerce/activity.atom?show_news=1');
        self::assertStringContainsString('eCommerce: News', (string) $this->client->getResponse()->getContent());
    }

    public function testFetcherVisibilityOfPrivateNotes(): void
    {
        $fetcher = static::getContainer()->get(ActivityFetcher::class);
        $em = $this->em;
        $admin = $em->getRepository(User::class)->findOneBy(['login' => 'admin']);
        $types = array_keys(ActivityFetcher::EVENT_TYPES);
        $adminEvents = $fetcher->events($admin, $types, null, true);
        $anon = static::getContainer()->get(\App\Security\CurrentUser::class)->anonymous();
        $anonEvents = $fetcher->events($anon, $types, null, true);
        self::assertGreaterThan(\count($anonEvents), \count($adminEvents));
        $previous = null;
        foreach ($adminEvents as $e) {
            if (null !== $previous) {
                self::assertGreaterThanOrEqual($e->datetime, $previous->datetime, 'reverse chronological');
            }
            $previous = $e;
        }
    }

    public function testSortEventsGroupsByIssue(): void
    {
        $d = new \DateTimeImmutable('2026-01-01 10:00');
        $mk = static fn (string $group, string $time, int $id) => new ActivityEvent('issue', $d->modify($time), 't', '/', null, null, null, $group, $id);
        $sorted = ActivitiesController::sortEvents([$mk('a', '+1 hour', 1), $mk('b', '+2 hours', 2), $mk('a', '+3 hours', 3)]);
        self::assertSame([3, 1, 2], array_map(static fn ($i) => $i['event']->id, $sorted));
        self::assertSame([false, true, false], array_map(static fn ($i) => $i['grouped'], $sorted));
    }

    public function testFormatActivityDescription(): void
    {
        self::assertSame('a<br>&gt; b<br>&gt; ...<br>c', ActivityExtension::formatActivityDescription("a\n> b\n> c\n> d\nc"));
        self::assertSame('x', ActivityExtension::formatActivityDescription("x\n<pre>code</pre>"));
        self::assertSame(240, mb_strlen(ActivityExtension::formatActivityDescription(str_repeat('a', 300))));
    }
}
